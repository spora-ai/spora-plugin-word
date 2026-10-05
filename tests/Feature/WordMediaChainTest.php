<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Models\MediaAsset;
use Spora\Plugins\Word\Exceptions\WordDocumentException;
use Spora\Plugins\Word\Producers\DocxToMarkdownProducer;
use Spora\Plugins\Word\Producers\MarkdownToDocxProducer;
use Spora\Plugins\Word\Refiners\WordDocxMimeRefiner;
use Spora\Plugins\Word\Services\WordConversion;
use Spora\Plugins\Word\Tests\Support\DeprecationFilter;
use Spora\Plugins\Word\Tests\Support\DocxFixtures;
use Spora\Plugins\Word\Tests\Support\WordMediaArchive;
use Spora\Services\MediaArchive\Exceptions\NoDerivativeProducerException;
use Spora\Services\MediaArchive\MediaDerivativeProducerDiscovery;
use Spora\Services\MediaArchive\MediaMimeRefinerDiscovery;

beforeEach(function () {
    DeprecationFilter::silencePhpWord();
    MediaDerivativeProducerDiscovery::add(MarkdownToDocxProducer::class);
    MediaDerivativeProducerDiscovery::add(DocxToMarkdownProducer::class);
    // Production registers this too: it types a `.docx` before the allowlist runs.
    MediaMimeRefinerDiscovery::add(WordDocxMimeRefiner::class);
});

afterEach(function () {
    DeprecationFilter::restore();
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

it('extracts a docx upload into an md derivative the archive can serve', function () {
    $container   = WordMediaArchive::container();
    $derivatives = WordMediaArchive::derivativeService($container);

    $parent = WordMediaArchive::ingestDocxUpload($container, DocxFixtures::docx());

    expect($parent->mime_type)->toBe(WordConversion::DOCX_MIME)
        ->and($parent->asset_url)->toEndWith('.docx');

    $derivative = $derivatives->createFromRequest($parent, 'md');

    expect($derivative->mime_type)->toBe('text/markdown')
        ->and($derivative->filename)->toBe('report.md')
        ->and($derivative->asset_url)->toEndWith('.md')
        ->and($derivative->media_type)->toBe('document')
        ->and((int) $derivative->byte_size)->toBeGreaterThan(0);

    $markdown = (string) $derivative->payload;
    expect($markdown)->toContain('# Quarterly report')
        ->and($markdown)->toContain('Revenue grew **12%** against a flat market.')
        ->and($markdown)->toContain('- North grew')
        ->and($markdown)->toContain('| **Region** | **Total** |');

    $join = Capsule::table('media_derivatives')->where('parent_id', $parent->id)->first();
    expect($join)->not->toBeNull()
        ->and($join->derivative_id)->toBe($derivative->id)
        ->and($join->format)->toBe('md')
        ->and($join->producer_plugin)->toBe('spora-plugin-word')
        ->and($join->producer_operation)->toBe('word.extract');
});

it('chains a docx into an md derivative and back into a docx derivative', function () {
    $container   = WordMediaArchive::container();
    $derivatives = WordMediaArchive::derivativeService($container);

    $parent = WordMediaArchive::ingestDocxUpload($container, DocxFixtures::docx());
    $md     = $derivatives->createFromRequest($parent, 'md');
    $docx   = $derivatives->createFromRequest($md, 'docx');

    expect($docx->mime_type)->toBe(WordConversion::DOCX_MIME)
        // `filenameFor()` drops the parent extension, so the second generation repeats the name.
        ->and($docx->filename)->toBe('report.docx')
        ->and(substr((string) $docx->payload, 0, 2))->toBe('PK');

    expect(MediaAsset::query()->count())->toBe(3)
        ->and(Capsule::table('media_derivatives')->count())->toBe(2)
        ->and($derivatives->parentOf($md->id))->toBe($parent->id)
        ->and($derivatives->parentOf($docx->id))->toBe($md->id)
        ->and(array_column($derivatives->listFor($parent->id), 'producer_operation'))
        ->toBe(['word.extract']);
});

it('resolves a docx parent whose mime came back as a coarse zip', function () {
    $container   = WordMediaArchive::container();
    $derivatives = WordMediaArchive::derivativeService($container);

    // No `word/document.xml`, so the refiner declines and the row keeps the coarse verdict.
    $parent = WordMediaArchive::ingestDocxUpload(
        $container,
        DocxFixtures::zipContaining(['notes.txt' => 'a plain archive']),
        'report.docx',
    );

    // What is pinned is the *routing*: it resolves on the extension, then fails loudly.
    expect($parent->mime_type)->toBe(DocxFixtures::ZIP_MIME);

    $caught = null;
    try {
        $derivatives->createFromRequest($parent, 'md');
    } catch (WordDocumentException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(WordDocumentException::class);

    $docx = WordMediaArchive::ingestDocxUpload($container, DocxFixtures::docx());
    $md   = $derivatives->createFromRequest($docx, 'md');
    expect($md->mime_type)->toBe('text/markdown');
});
