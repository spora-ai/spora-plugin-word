<?php

declare(strict_types=1);

use MarkdownWord\Exception\UnreadableDocument;
use Spora\Plugins\Word\Exceptions\WordDocumentException;
use Spora\Plugins\Word\Exceptions\WordInvalidArgumentException;
use Spora\Plugins\Word\Exceptions\WordRuntimeException;
use Spora\Plugins\Word\Services\WordConversion;
use Spora\Plugins\Word\Tests\Support\DeprecationFilter;
use Spora\Plugins\Word\Tests\Support\DocxFixtures;
use Spora\Plugins\Word\Tests\Support\ErrorHandlerStack;
use Spora\Plugins\Word\Tests\Support\ErrorRecorder;
use Spora\Plugins\Word\Tests\Support\WordMediaArchive;
use Spora\Services\MediaArchive\MediaArchiveService;

beforeEach(function () {
    DeprecationFilter::silencePhpWord();
});

afterEach(function () {
    DeprecationFilter::restore();
});

it('declares the identity the media_derivatives natural key is built from', function () {
    $producer = WordMediaArchive::extractor(WordMediaArchive::container());

    expect($producer->pluginSlug())->toBe('spora-plugin-word')
        ->and($producer->operationName())->toBe('word.extract')
        ->and($producer->supportedDerivativeFormats())->toBe(['md'])
        ->and($producer->supportedSourceFormats())->toBe([WordConversion::DOCX_MIME, 'docx'])
        ->and(strlen((string) $producer->supportedDerivativeFormats()[0]))->toBeLessThanOrEqual(16);
});

/**
 * Mirrors `MediaAllowedTypesService`: every producer's source formats, minus the
 * entries without a `/`. A change to core's filter breaks this test.
 */
it('keeps the docx mime in the upload allowlist without widening it to the zip container', function () {
    $container = WordMediaArchive::container();
    $extract   = WordMediaArchive::extractor($container);
    $render    = WordMediaArchive::producer($container);

    $allowlist = [];
    foreach ([$extract, $render] as $producer) {
        foreach ($producer->supportedSourceFormats() as $format) {
            if (str_contains($format, '/')) {
                $allowlist[] = $format;
            }
        }
    }

    expect($allowlist)->toContain(WordConversion::DOCX_MIME)
        ->and($allowlist)->toContain('text/markdown')
        ->and($allowlist)->not->toContain(DocxFixtures::ZIP_MIME);
});

it('round-trips a real document back into github-flavoured markdown', function () {
    $producer = WordMediaArchive::extractor(WordMediaArchive::container());

    $output = $producer->produce(WordMediaArchive::docxAsset(DocxFixtures::docx()), 'md');

    expect($output->bytes)->toContain('# Quarterly report')
        ->and($output->bytes)->toContain('Revenue grew **12%** against a flat market.')
        ->and($output->bytes)->toContain('- North grew')
        ->and($output->bytes)->toContain('- South held')
        ->and($output->bytes)->toContain('| **Region** | **Total** |')
        ->and($output->bytes)->toContain('| :-- | --: |')
        ->and($output->bytes)->toContain('| North | 1,200 |');
});

it('emits the exact source format the render producer accepts', function () {
    $extract = WordMediaArchive::extractor(WordMediaArchive::container());
    $render  = WordMediaArchive::producer(WordMediaArchive::container());

    $output = $extract->produce(WordMediaArchive::docxAsset(DocxFixtures::docx()), 'md');

    // `findProducer()` matches the parent's MIME first and its extension second.
    expect($render->supportedSourceFormats())->toContain($output->mime)
        ->and(MediaArchiveService::extensionForMime($output->mime))
        ->toBe($extract->supportedDerivativeFormats()[0]);
});

it('rejects a format it cannot emit before it touches the asset', function () {
    $producer = WordMediaArchive::extractor(WordMediaArchive::container());
    $caught   = null;

    // `external` holds no bytes, so a `WordRuntimeException` means the gate ran too late.
    try {
        $producer->produce(WordMediaArchive::externalAsset('report.docx'), 'docx');
    } catch (WordInvalidArgumentException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(WordInvalidArgumentException::class)
        ->and($caught->getMessage())->toContain('docx')
        ->and($caught->getMessage())->toContain('md');
});

it('tolerates a padded or mixed-case format identifier', function () {
    $producer = WordMediaArchive::extractor(WordMediaArchive::container());

    $output = $producer->produce(WordMediaArchive::docxAsset(DocxFixtures::docx()), ' MD ');

    expect($output->mime)->toBe('text/markdown')
        ->and($output->bytes)->toContain('# Quarterly report');
});

it('reads a local-mode document back off disk', function () {
    $container = WordMediaArchive::container();
    $producer  = WordMediaArchive::extractor($container);

    // The branch that installs its own `set_error_handler` around the read.
    $docx  = DocxFixtures::docx();
    $local = WordMediaArchive::localDocxAsset($container, $docx);
    $output = $producer->produce($local['asset'], 'md');

    expect($output->bytes)->toContain('# Quarterly report')
        ->and(file_get_contents($local['store']->readFromAsset($local['asset'])['path']))
        ->toBe($docx);
});

it('names a remedy that exists for an externally stored document', function () {
    $producer = WordMediaArchive::extractor(WordMediaArchive::container());
    $caught   = null;

    try {
        $producer->produce(WordMediaArchive::externalAsset('report.docx'), 'md');
    } catch (WordRuntimeException $e) {
        $caught = $e;
    }

    // For a `.docx` parent `create_media` is no remedy: it must arrive as an upload.
    expect($caught)->toBeInstanceOf(WordRuntimeException::class)
        ->and($caught->getMessage())->toContain('external')
        ->and($caught->getMessage())->toContain('create_media');
});

it('decides from the bytes, not from the row it was handed', function () {
    $producer = WordMediaArchive::extractor(WordMediaArchive::container());

    $ole    = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat("\x00", 512);
    $caught = null;

    try {
        $producer->produce(WordMediaArchive::docxAsset($ole, 'legacy.docx'), 'md');
    } catch (WordDocumentException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(WordDocumentException::class)
        // Survives as `$previous`, so the line the caller logs keeps its wording.
        ->and($caught->getPrevious())->toBeInstanceOf(UnreadableDocument::class)
        ->and($caught->getMessage())->toContain('Reading the Word document');

    $lying = WordMediaArchive::docxAsset(DocxFixtures::docx(), 'report.bin');
    $lying->mime_type = 'application/octet-stream';

    expect($producer->produce($lying, 'md')->bytes)->toContain('# Quarterly report');
});

it('refuses bytes that are not an archive at all', function () {
    $producer = WordMediaArchive::extractor(WordMediaArchive::container());
    $caught   = null;

    try {
        $producer->produce(WordMediaArchive::docxAsset('this is not a zip archive', 'notes.docx'), 'md');
    } catch (WordDocumentException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(WordDocumentException::class)
        ->and($caught->getPrevious())->toBeInstanceOf(UnreadableDocument::class);
});

it('refuses a document that was cut short after its first entries', function () {
    $producer = WordMediaArchive::extractor(WordMediaArchive::container());
    $truncated = DocxFixtures::truncatedDocx();
    $caught   = null;

    // `finfo` still names a truncated file a Word document, so it does reach this producer.
    try {
        $producer->produce(WordMediaArchive::docxAsset($truncated, 'broken.docx'), 'md');
    } catch (WordDocumentException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(WordDocumentException::class)
        ->and(strlen($truncated))->toBe(900);
});

it('leaves the error-handler stack balanced and still suppressing on both storage paths', function () {
    $container = WordMediaArchive::container();
    $producer  = WordMediaArchive::extractor($container);
    $docx      = DocxFixtures::docx();

    $sentinel = new ErrorRecorder();
    set_error_handler($sentinel, E_ALL);

    $leaked      = [];
    $unreadable  = null;

    try {
        $producer->produce(WordMediaArchive::docxAsset($docx), 'md');
        $leaked['data_url'] = ErrorHandlerStack::leakedFramesAbove($sentinel);

        $local = WordMediaArchive::localDocxAsset($container, $docx);
        $producer->produce($local['asset'], 'md');
        $leaked['local'] = ErrorHandlerStack::leakedFramesAbove($sentinel);

        // Only an unreadable-but-present file reaches the `file_get_contents` under
        // test: a missing one is caught by the store, a zero-byte one is silent.
        $denied = WordMediaArchive::localDocxAsset($container, $docx);
        $path   = (string) $denied['store']->readFromAsset($denied['asset'])['path'];
        chmod($path, 0000);
        try {
            $producer->produce($denied['asset'], 'md');
        } catch (WordDocumentException $e) {
            $unreadable = $e;
        } finally {
            chmod($path, 0644);
        }
    } finally {
        restore_error_handler();
    }

    expect($leaked)->toBe(['data_url' => 0, 'local' => 0])
        ->and(array_column($sentinel->entries(), 'errno'))->not->toContain(E_WARNING)
        ->and($unreadable)->toBeInstanceOf(WordDocumentException::class)
        ->and($unreadable->getMessage())->toContain('local file is unreadable');
});

it('does not share an operation name with the render producer', function () {
    $extract = WordMediaArchive::extractor(WordMediaArchive::container());
    $render  = WordMediaArchive::producer(WordMediaArchive::container());

    expect($extract->operationName())->not->toBe($render->operationName())
        ->and($extract->pluginSlug())->toBe($render->pluginSlug());
});
