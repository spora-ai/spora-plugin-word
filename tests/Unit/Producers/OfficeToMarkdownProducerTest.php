<?php

declare(strict_types=1);

use MarkdownWord\Exception\UnreadableDocument;
use Spora\Plugins\Office\Exceptions\OfficeDocumentException;
use Spora\Plugins\Office\Exceptions\OfficeInvalidArgumentException;
use Spora\Plugins\Office\Exceptions\OfficeRuntimeException;
use Spora\Plugins\Office\Services\OfficeConversion;
use Spora\Plugins\Office\Tests\Support\DeprecationFilter;
use Spora\Plugins\Office\Tests\Support\DocxFixtures;
use Spora\Plugins\Office\Tests\Support\ErrorHandlerStack;
use Spora\Plugins\Office\Tests\Support\ErrorRecorder;
use Spora\Plugins\Office\Tests\Support\OfficeMediaArchive;
use Spora\Services\MediaArchive\MediaArchiveService;

beforeEach(function () {
    DeprecationFilter::silencePhpWord();
});

afterEach(function () {
    DeprecationFilter::restore();
});

it('declares the identity the media_derivatives natural key is built from', function () {
    $producer = OfficeMediaArchive::extractor(OfficeMediaArchive::container());

    expect($producer->pluginSlug())->toBe('spora-plugin-office')
        ->and($producer->operationName())->toBe('office.extract')
        ->and($producer->supportedDerivativeFormats())->toBe(['md'])
        ->and($producer->supportedSourceFormats())->toBe([OfficeConversion::DOCX_MIME, 'docx'])
        ->and(strlen((string) $producer->supportedDerivativeFormats()[0]))->toBeLessThanOrEqual(16);
});

/**
 * Mirrors `MediaAllowedTypesService`: every producer's source formats, minus the
 * entries without a `/`. A change to core's filter breaks this test.
 */
it('keeps the docx mime in the upload allowlist without widening it to the zip container', function () {
    $container = OfficeMediaArchive::container();
    $extract   = OfficeMediaArchive::extractor($container);
    $render    = OfficeMediaArchive::producer($container);

    $allowlist = [];
    foreach ([$extract, $render] as $producer) {
        foreach ($producer->supportedSourceFormats() as $format) {
            if (str_contains($format, '/')) {
                $allowlist[] = $format;
            }
        }
    }

    expect($allowlist)->toContain(OfficeConversion::DOCX_MIME)
        ->and($allowlist)->toContain('text/markdown')
        ->and($allowlist)->not->toContain(DocxFixtures::ZIP_MIME);
});

it('round-trips a real document back into github-flavoured markdown', function () {
    $producer = OfficeMediaArchive::extractor(OfficeMediaArchive::container());

    $output = $producer->produce(OfficeMediaArchive::docxAsset(DocxFixtures::docx()), 'md');

    expect($output->bytes)->toContain('# Quarterly report')
        ->and($output->bytes)->toContain('Revenue grew **12%** against a flat market.')
        ->and($output->bytes)->toContain('- North grew')
        ->and($output->bytes)->toContain('- South held')
        ->and($output->bytes)->toContain('| **Region** | **Total** |')
        ->and($output->bytes)->toContain('| :-- | --: |')
        ->and($output->bytes)->toContain('| North | 1,200 |');
});

it('emits the exact source format the render producer accepts', function () {
    $extract = OfficeMediaArchive::extractor(OfficeMediaArchive::container());
    $render  = OfficeMediaArchive::producer(OfficeMediaArchive::container());

    $output = $extract->produce(OfficeMediaArchive::docxAsset(DocxFixtures::docx()), 'md');

    // `findProducer()` matches the parent's MIME first and its extension second.
    expect($render->supportedSourceFormats())->toContain($output->mime)
        ->and(MediaArchiveService::extensionForMime($output->mime))
        ->toBe($extract->supportedDerivativeFormats()[0]);
});

it('rejects a format it cannot emit before it touches the asset', function () {
    $producer = OfficeMediaArchive::extractor(OfficeMediaArchive::container());
    $caught   = null;

    // `external` holds no bytes, so a `OfficeRuntimeException` means the gate ran too late.
    try {
        $producer->produce(OfficeMediaArchive::externalAsset('report.docx'), 'docx');
    } catch (OfficeInvalidArgumentException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(OfficeInvalidArgumentException::class)
        ->and($caught->getMessage())->toContain('docx')
        ->and($caught->getMessage())->toContain('md');
});

it('tolerates a padded or mixed-case format identifier', function () {
    $producer = OfficeMediaArchive::extractor(OfficeMediaArchive::container());

    $output = $producer->produce(OfficeMediaArchive::docxAsset(DocxFixtures::docx()), ' MD ');

    expect($output->mime)->toBe('text/markdown')
        ->and($output->bytes)->toContain('# Quarterly report');
});

it('reads a local-mode document back off disk', function () {
    $container = OfficeMediaArchive::container();
    $producer  = OfficeMediaArchive::extractor($container);

    // The branch that installs its own `set_error_handler` around the read.
    $docx  = DocxFixtures::docx();
    $local = OfficeMediaArchive::localDocxAsset($container, $docx);
    $output = $producer->produce($local['asset'], 'md');

    expect($output->bytes)->toContain('# Quarterly report')
        ->and(file_get_contents($local['store']->readFromAsset($local['asset'])['path']))
        ->toBe($docx);
});

it('names a remedy that exists for an externally stored document', function () {
    $producer = OfficeMediaArchive::extractor(OfficeMediaArchive::container());
    $caught   = null;

    try {
        $producer->produce(OfficeMediaArchive::externalAsset('report.docx'), 'md');
    } catch (OfficeRuntimeException $e) {
        $caught = $e;
    }

    // For a `.docx` parent `create_media` is no remedy: it must arrive as an upload.
    expect($caught)->toBeInstanceOf(OfficeRuntimeException::class)
        ->and($caught->getMessage())->toContain('external')
        ->and($caught->getMessage())->toContain('create_media');
});

it('decides from the bytes, not from the row it was handed', function () {
    $producer = OfficeMediaArchive::extractor(OfficeMediaArchive::container());

    $ole    = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat("\x00", 512);
    $caught = null;

    try {
        $producer->produce(OfficeMediaArchive::docxAsset($ole, 'legacy.docx'), 'md');
    } catch (OfficeDocumentException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(OfficeDocumentException::class)
        // Survives as `$previous`, so the line the caller logs keeps its wording.
        ->and($caught->getPrevious())->toBeInstanceOf(UnreadableDocument::class)
        ->and($caught->getMessage())->toContain('Reading the Word document');

    $lying = OfficeMediaArchive::docxAsset(DocxFixtures::docx(), 'report.bin');
    $lying->mime_type = 'application/octet-stream';

    expect($producer->produce($lying, 'md')->bytes)->toContain('# Quarterly report');
});

it('refuses bytes that are not an archive at all', function () {
    $producer = OfficeMediaArchive::extractor(OfficeMediaArchive::container());
    $caught   = null;

    try {
        $producer->produce(OfficeMediaArchive::docxAsset('this is not a zip archive', 'notes.docx'), 'md');
    } catch (OfficeDocumentException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(OfficeDocumentException::class)
        ->and($caught->getPrevious())->toBeInstanceOf(UnreadableDocument::class);
});

it('refuses a document that was cut short after its first entries', function () {
    $producer = OfficeMediaArchive::extractor(OfficeMediaArchive::container());
    $truncated = DocxFixtures::truncatedDocx();
    $caught   = null;

    // `finfo` still names a truncated file a Word document, so it does reach this producer.
    try {
        $producer->produce(OfficeMediaArchive::docxAsset($truncated, 'broken.docx'), 'md');
    } catch (OfficeDocumentException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(OfficeDocumentException::class)
        ->and(strlen($truncated))->toBe(900);
});

it('leaves the error-handler stack balanced and still suppressing on both storage paths', function () {
    $container = OfficeMediaArchive::container();
    $producer  = OfficeMediaArchive::extractor($container);
    $docx      = DocxFixtures::docx();

    $sentinel = new ErrorRecorder();
    set_error_handler($sentinel, E_ALL);

    $leaked      = [];
    $unreadable  = null;

    try {
        $producer->produce(OfficeMediaArchive::docxAsset($docx), 'md');
        $leaked['data_url'] = ErrorHandlerStack::leakedFramesAbove($sentinel);

        $local = OfficeMediaArchive::localDocxAsset($container, $docx);
        $producer->produce($local['asset'], 'md');
        $leaked['local'] = ErrorHandlerStack::leakedFramesAbove($sentinel);

        // Only an unreadable-but-present file reaches the `file_get_contents` under
        // test: a missing one is caught by the store, a zero-byte one is silent.
        $denied = OfficeMediaArchive::localDocxAsset($container, $docx);
        $path   = (string) $denied['store']->readFromAsset($denied['asset'])['path'];
        chmod($path, 0000);
        try {
            $producer->produce($denied['asset'], 'md');
        } catch (OfficeDocumentException $e) {
            $unreadable = $e;
        } finally {
            chmod($path, 0644);
        }
    } finally {
        restore_error_handler();
    }

    expect($leaked)->toBe(['data_url' => 0, 'local' => 0])
        ->and(array_column($sentinel->entries(), 'errno'))->not->toContain(E_WARNING)
        ->and($unreadable)->toBeInstanceOf(OfficeDocumentException::class)
        ->and($unreadable->getMessage())->toContain('local file is unreadable');
});

it('does not share an operation name with the render producer', function () {
    $extract = OfficeMediaArchive::extractor(OfficeMediaArchive::container());
    $render  = OfficeMediaArchive::producer(OfficeMediaArchive::container());

    expect($extract->operationName())->not->toBe($render->operationName())
        ->and($extract->pluginSlug())->toBe($render->pluginSlug());
});
