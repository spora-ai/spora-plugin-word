<?php

declare(strict_types=1);

use Spora\Models\MediaAsset;
use Spora\Plugins\Word\Converters\DocxToMarkdownConverter;
use Spora\Plugins\Word\Refiners\WordDocxMimeRefiner;
use Spora\Plugins\Word\Services\WordConversion;
use Spora\Plugins\Word\Tests\Support\DeprecationFilter;
use Spora\Plugins\Word\Tests\Support\DocxFixtures;
use Spora\Plugins\Word\Tests\Support\RecordingLogger;
use Spora\Plugins\Word\Tests\Support\WordMediaArchive;
use Spora\Services\MediaArchive\MediaConverterDiscovery;
use Spora\Services\MediaArchive\MediaMimeRefinerDiscovery;

/**
 * What the plugin contributes here is the converter registration plus the
 * refiner. Both registries are process-global statics, and
 * `MediaConverterRegistry` snapshots `MediaConverterDiscovery` when the
 * container resolves it, so registration has to happen before any test
 * builds the archive service.
 */
beforeEach(function () {
    DeprecationFilter::silencePhpWord();
    MediaConverterDiscovery::add(DocxToMarkdownConverter::class);
    // Production registers this too, and it is what types a `.docx` before
    // the upload allowlist runs on a host whose libmagic reports every
    // OOXML package as a bare zip.
    MediaMimeRefinerDiscovery::add(WordDocxMimeRefiner::class);
});

afterEach(function () {
    DeprecationFilter::restore();
});

/**
 * The payoff of registering the converter at all:
 * `media_assets.markdown_content` is what
 * `MessageHistoryBuilder::buildTextBlock()` inlines into the model's
 * context, so this is the difference between the agent reading the
 * attachment and seeing nothing but a filename.
 */
it('extracts github-flavoured markdown from a docx at ingest', function () {
    $logger = new RecordingLogger();

    $asset = WordMediaArchive::ingestDocxUpload(WordMediaArchive::container($logger), DocxFixtures::docx());

    expect($asset->mime_type)->toBe(WordConversion::DOCX_MIME)
        ->and($asset->asset_url)->toEndWith('.docx')
        ->and($asset->markdown_content)->not->toBeNull();

    // Structure, not just "non-empty": a converter that flattened the
    // document to a single paragraph would satisfy a length check and hand
    // the model something with no headings, lists or table to reason about.
    expect($asset->markdown_content)->toContain('# Quarterly report')
        ->and($asset->markdown_content)->toContain('Revenue grew **12%** against a flat market.')
        ->and($asset->markdown_content)->toContain('- North grew')
        ->and($asset->markdown_content)->toContain('| **Region** | **Total** |')
        ->and($asset->markdown_content)->toContain('| :-- | --: |');

    // Nothing degraded: a clean document must not cost a warning line.
    expect($logger->warnings())->toBe([]);
});

/**
 * The best-effort contract, and the reason the converter is allowed to let
 * `WordDocumentException` propagate: `runConversionPipeline()` catches
 * `Throwable`, logs it at `warning` and returns. A hostile or corrupt
 * attachment must not be able to fail the upload — the chat degrades to the
 * metadata block instead.
 */
it('leaves a corrupt docx with null markdown_content without failing the ingest', function () {
    $container = WordMediaArchive::container(new RecordingLogger());
    $truncated = DocxFixtures::truncatedDocx();

    $asset = WordMediaArchive::ingestDocxUpload($container, $truncated, 'broken.docx');

    // The row is fully persisted: the archive can serve the download, the
    // media library lists it, and the user still has their file.
    expect($asset->id)->not->toBe('');

    $persisted = MediaAsset::query()->find($asset->id);
    expect($persisted)->not->toBeNull()
        ->and($persisted->storage_mode)->toBe('data_url')
        ->and((int) $persisted->byte_size)->toBe(strlen($truncated))
        // Re-read from the database, not from the in-memory model, so an
        // accidental write cannot pass as "unset".
        ->and($persisted->markdown_content)->toBeNull();
});

it('logs a warning naming the failed conversion instead of throwing', function () {
    $logger    = new RecordingLogger();
    $container = WordMediaArchive::container($logger);

    WordMediaArchive::ingestDocxUpload($container, DocxFixtures::truncatedDocx(), 'broken.docx');

    // The record is the only way to tell "the converter gave up" apart from
    // "no converter claimed this MIME": both leave a NULL `markdown_content`.
    $warnings = $logger->warnings();

    expect($warnings)->toHaveCount(1)
        ->and($warnings[0]['message'])->toBe('MediaArchiveService: converter failed')
        ->and($warnings[0]['context']['mime'])->toBe(WordConversion::DOCX_MIME)
        ->and($warnings[0]['context']['error'])->toContain('Reading the Word document');
});

/**
 * A `.docx` is correctly typed upstream of the allowlist because the refiner
 * upgrades a coarse `application/zip` verdict. On a libmagic build that
 * already names the format the upgrade is a no-op, so this asserts the
 * invariant that holds either way: a real Word package lands on the DOCX
 * MIME, and a bare zip keeps the coarse one.
 */
it('types a word package correctly and leaves a bare zip coarse', function () {
    $logger    = new RecordingLogger();
    $container = WordMediaArchive::container($logger);

    $docx = WordMediaArchive::ingestDocxUpload($container, DocxFixtures::docx());
    $zip  = WordMediaArchive::ingestDocxUpload(
        $container,
        DocxFixtures::zipContaining(['notes.txt' => 'a plain archive']),
        'bundle.zip',
    );

    expect($docx->mime_type)->toBe(WordConversion::DOCX_MIME)
        ->and($docx->markdown_content)->not->toBeNull()
        // The guard against xlsx/pptx: claiming every `PK\x03\x04` blob
        // would relabel spreadsheets as Word documents.
        ->and($zip->mime_type)->toBe('application/zip')
        ->and($zip->markdown_content)->toBeNull()
        ->and($logger->warnings())->toBe([]);
});
