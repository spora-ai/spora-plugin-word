<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Models\MediaAsset;
use Spora\Plugins\Word\Producers\MarkdownToDocxProducer;
use Spora\Plugins\Word\Services\WordConversion;
use Spora\Plugins\Word\Tests\Support\WordMediaArchive;
use Spora\Services\MediaArchive\Exceptions\NoDerivativeProducerException;
use Spora\Services\MediaArchive\MediaDerivativeProducerDiscovery;

beforeEach(function () {
    // `WordPlugin::onContainerBuilding()` does this at boot; the test
    // registers by hand so the chain is exercised without a kernel. The
    // producer is then resolved *through the container* by
    // `MediaDerivativeService::findProducer()`.
    MediaDerivativeProducerDiscovery::add(MarkdownToDocxProducer::class);
});

/**
 * The two calls an agent makes, end to end: `create_media` stores the
 * Markdown, `create_derivative` renders it into a Word document.
 */
it('renders a stored markdown asset into a downloadable docx derivative', function () {
    $container   = WordMediaArchive::container();
    $derivatives = WordMediaArchive::derivativeService($container);

    $parent = WordMediaArchive::ingestMarkdown($container);

    // `ingestFromBytes()` always re-sniffs: `create_media`'s `text/markdown`
    // is a hint, never a claim, so the row lands as whatever the sniffer makes
    // of the bytes. libmagic calls any prose `text/plain`; the `.md` extension
    // is what refines that to `text/markdown`, which is the MIME the producer
    // advertises. Both the MIME and the extension match on their own, so the
    // chain does not depend on the filename having been spelled out.
    expect($parent->mime_type)->toBe('text/markdown')
        ->and($parent->storage_mode)->toBe('data_url');

    $derivative = $derivatives->createFromRequest($parent, 'docx');

    expect($derivative->mime_type)->toBe(WordConversion::DOCX_MIME)
        ->and($derivative->asset_url)->toEndWith('.docx')
        ->and((int) $derivative->byte_size)->toBeGreaterThan(100)
        ->and((int) $derivative->byte_size)->toBe(strlen((string) $derivative->payload))
        ->and(substr((string) $derivative->payload, 0, 2))->toBe('PK')
        ->and($derivative->filename)->toBe('report.docx')
        ->and($derivative->media_type)->toBe('document');

    // The join row is where the plugin's identity is persisted — the
    // attribution the LIST endpoints filter on and the natural key dedupes
    // against.
    $join = Capsule::table('media_derivatives')->where('parent_id', $parent->id)->first();
    expect($join)->not->toBeNull()
        ->and($join->derivative_id)->toBe($derivative->id)
        ->and($join->format)->toBe('docx')
        ->and($join->producer_plugin)->toBe('spora-plugin-word')
        ->and($join->producer_operation)->toBe('word.render');
});

it('is idempotent on the natural key, so a retry reuses the same derivative', function () {
    $container   = WordMediaArchive::container();
    $derivatives = WordMediaArchive::derivativeService($container);
    $parent      = WordMediaArchive::ingestMarkdown($container);

    $first  = $derivatives->createFromRequest($parent, 'docx');
    $second = $derivatives->createFromRequest($parent, 'docx');

    expect($second->id)->toBe($first->id)
        ->and(Capsule::table('media_derivatives')->where('parent_id', $parent->id)->count())->toBe(1)
        // Exactly two assets exist: the parent and the one derivative. A
        // duplicate `media_assets` row would be an orphaned download card
        // in the chat that no join row explains.
        ->and(MediaAsset::query()->count())->toBe(2);
});

it('reports a format it cannot produce as a conflict, not as a broken document', function () {
    $container   = WordMediaArchive::container();
    $derivatives = WordMediaArchive::derivativeService($container);
    $parent      = WordMediaArchive::ingestMarkdown($container);
    $caught      = null;

    try {
        $derivatives->createFromRequest($parent, 'pdf');
    } catch (NoDerivativeProducerException $e) {
        $caught = $e;
    }

    // The HTTP controller maps this to 409 and the `media` tool to a
    // `ToolResult::fail()`, so the LLM reads an explanation it can act on
    // rather than a 422 from a producer that never ran.
    expect($caught)->toBeInstanceOf(NoDerivativeProducerException::class)
        ->and($caught->getMessage())->toContain('pdf')
        ->and($caught->getMessage())->toContain($parent->id);
});

it('cleans the join row up when the parent asset is deleted', function () {
    $container   = WordMediaArchive::container();
    $archive     = WordMediaArchive::archiveService($container);
    $derivatives = WordMediaArchive::derivativeService($container);

    $parent     = WordMediaArchive::ingestMarkdown($container);
    $derivative = $derivatives->createFromRequest($parent, 'docx');

    expect($derivatives->parentOf($derivative->id))->toBe($parent->id)
        ->and(Capsule::table('media_derivatives')->where('parent_id', $parent->id)->count())->toBe(1);

    $archive->delete($parent->id);

    // `fk_media_derivatives_parent` is `cascadeOnDelete`, so a deleted
    // parent leaves no link row behind — which is exactly what
    // `MediaDerivativeService::parentOf()` reverse-lookup resolves against.
    expect(Capsule::table('media_derivatives')->where('parent_id', $parent->id)->count())->toBe(0)
        ->and(MediaAsset::query()->find($parent->id))->toBeNull()
        ->and($derivatives->parentOf($derivative->id))->toBeNull();
});

it('reads the rendered derivative back as markdown through the converter', function () {
    $container   = WordMediaArchive::container();
    $derivatives = WordMediaArchive::derivativeService($container);

    $parent     = WordMediaArchive::ingestMarkdown($container);
    $derivative = $derivatives->createFromRequest($parent, 'docx');

    // The loop an agent actually walks: render a document, then read it
    // back to check or edit it. Both directions are this plugin, so a break
    // in the second must not be masked by the first succeeding.
    $markdown = WordMediaArchive::converter($container)
        ->toMarkdown((string) $derivative->payload, WordConversion::DOCX_MIME, (string) $derivative->filename);

    expect($markdown)->toContain('# Quarterly report')
        ->and($markdown)->toContain('| **Region** | **Total** |');
});
