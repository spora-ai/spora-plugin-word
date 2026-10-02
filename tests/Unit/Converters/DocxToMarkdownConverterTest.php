<?php

declare(strict_types=1);

use MarkdownWord\Exception\UnreadableDocument;
use Spora\Plugins\Word\Converters\DocxToMarkdownConverter;
use Spora\Plugins\Word\Exceptions\WordDocumentException;
use Spora\Plugins\Word\Services\WordConversion;
use Spora\Plugins\Word\Tests\Support\DeprecationFilter;
use Spora\Plugins\Word\Tests\Support\DocxFixtures;
use Spora\Plugins\Word\Tests\Support\WordMediaArchive;
use Spora\Services\MediaArchive\MediaConverterDiscovery;

beforeEach(function () {
    DeprecationFilter::silencePhpWord();
});

afterEach(function () {
    DeprecationFilter::restore();
});

it('claims exactly the docx mime and extension', function () {
    $converter = WordMediaArchive::converter(WordMediaArchive::container());

    expect($converter->supportedMimeTypes())->toBe([WordConversion::DOCX_MIME])
        ->and($converter->supportedExtensions())->toBe(['docx']);
});

/**
 * The negative that carries the most weight in this class.
 *
 * `MediaConverterRegistry::allSupportedMimeTypes()` is the *union* of every
 * registered converter's MIMEs, and `MediaAllowedTypesService` publishes
 * that union as the upload allowlist. One stray `application/zip` here would
 * put every zip archive in the world into the chat picker's `accept`
 * attribute, so this asserts the union an operator would actually ship
 * rather than the converter's own list.
 */
it('never widens the upload allowlist to the zip container type', function () {
    MediaConverterDiscovery::add(DocxToMarkdownConverter::class);

    $container = WordMediaArchive::container();
    $converter = WordMediaArchive::converter($container);
    $registry  = WordMediaArchive::converterRegistry($container);

    expect($converter->supportedMimeTypes())->not->toContain('application/zip')
        ->and($registry->allSupportedMimeTypes())->toBe([WordConversion::DOCX_MIME])
        ->and($registry->allSupportedMimeTypes())->not->toContain('application/zip');
});

it('is the converter the registry resolves for a docx, by mime and by extension', function () {
    MediaConverterDiscovery::add(DocxToMarkdownConverter::class);

    $container = WordMediaArchive::container();
    $registry  = WordMediaArchive::converterRegistry($container);

    // The extension fallback is the second line of defence behind
    // `WordDocxMimeRefiner`: even when the bytes sniffed as a bare zip, a
    // filename ending `.docx` still reaches this converter.
    expect($registry->findFor(WordConversion::DOCX_MIME, 'report.docx'))
        ->toBeInstanceOf(DocxToMarkdownConverter::class)
        ->and($registry->findFor('application/zip', 'report.docx'))
        ->toBeInstanceOf(DocxToMarkdownConverter::class);
});

it('round-trips a real document back into github-flavoured markdown', function () {
    $converter = WordMediaArchive::converter(WordMediaArchive::container());

    $markdown = $converter->toMarkdown(DocxFixtures::docx(), WordConversion::DOCX_MIME, 'report.docx');

    // Structure, not just "non-empty": a converter that returned a single
    // line of prose would satisfy a length check and hand the model
    // something with no headings, lists or tables to reason about.
    expect($markdown)->toContain('# Quarterly report')
        ->and($markdown)->toContain('Revenue grew **12%** against a flat market.')
        ->and($markdown)->toContain('- North grew')
        ->and($markdown)->toContain('- South held')
        // The round trip's documented header-row rewrite: the alignment
        // markers survive, the cell emphasis does not.
        ->and($markdown)->toContain('| **Region** | **Total** |')
        ->and($markdown)->toContain('| :-- | --: |')
        ->and($markdown)->toContain('| North | 1,200 |');
});

it('decides from the bytes, not from a lying mime or filename', function () {
    $converter = WordMediaArchive::converter(WordMediaArchive::container());

    // A legacy OLE `.doc` is a different format entirely. Renaming it
    // `.docx` must not make this converter attempt a read it cannot do.
    $ole   = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat("\x00", 512);
    $caught = null;

    try {
        $converter->toMarkdown($ole, WordConversion::DOCX_MIME, 'legacy.docx');
    } catch (WordDocumentException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(WordDocumentException::class)
        // The upstream exception survives as `$previous`: the ingest
        // pipeline logs `$e->getMessage()`, and that line should still carry
        // the library's own wording.
        ->and($caught->getPrevious())->toBeInstanceOf(UnreadableDocument::class)
        ->and($caught->getMessage())->toContain('Reading the Word document')
        // A real document is unaffected by a wrong hint — the same bytes
        // read fine when the caller lies about the MIME.
        ->and($converter->toMarkdown(DocxFixtures::docx(), 'application/octet-stream', 'report.bin'))
        ->toContain('# Quarterly report');
});

it('refuses bytes that are not an archive at all', function () {
    $converter = WordMediaArchive::converter(WordMediaArchive::container());
    $caught    = null;

    try {
        $converter->toMarkdown('this is not a zip archive', WordConversion::DOCX_MIME, 'notes.docx');
    } catch (WordDocumentException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(WordDocumentException::class)
        ->and($caught->getPrevious())->toBeInstanceOf(UnreadableDocument::class);
});

it('refuses a document that was cut short after its first entries', function () {
    $converter = WordMediaArchive::converter(WordMediaArchive::container());
    $truncated = DocxFixtures::truncatedDocx();
    $caught    = null;

    try {
        $converter->toMarkdown($truncated, WordConversion::DOCX_MIME, 'broken.docx');
    } catch (WordDocumentException $e) {
        $caught = $e;
    }

    // `finfo` still names a truncated file a Word document from its local
    // file header, so it really does reach this converter — the failure
    // has to happen here for the upload's `markdown_content` to degrade to
    // NULL instead of the upload failing.
    expect($caught)->toBeInstanceOf(WordDocumentException::class)
        ->and(strlen($truncated))->toBe(900);
});
