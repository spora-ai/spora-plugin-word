<?php

declare(strict_types=1);

use MarkdownWord\Exception\UnreadableDocument;
use MarkdownWord\MarkdownToWord;
use Spora\Plugins\Word\Exceptions\WordDocumentException;
use Spora\Plugins\Word\Services\WordConversion;
use Spora\Plugins\Word\Tests\Support\DocxFixtures;
use Spora\Plugins\Word\Tests\Support\ErrorRecorder;

/**
 * Raise one genuine engine-level `E_DEPRECATED` attributed to *this* file.
 *
 * PHP 8.4 deprecated implicitly-nullable parameter declarations, so `eval`ing
 * the snippet compiles a real deprecation whose `$errfile` is the calling
 * file. That is the only way to exercise the suppressor's `$errfile` conjunct:
 * `E_USER_*` cannot, because the handler's `E_DEPRECATED` mask never routes
 * those levels to it at all — a test using them would pass whether or not the
 * suppressor existed.
 *
 * `$seq` is part of the function name so every call declares a fresh symbol
 * instead of fataling on a redeclaration.
 */
function raise_engine_deprecation(int $seq): void
{
    $function = 'spora_word_deprecation_probe_' . $seq;
    eval("function {$function}(string \$value = null): string { return (string) \$value; }");
    $function();
}

/**
 * Invoke the private deprecation-suppressing wrapper. It is the plugin's
 * whole defence against PHPWord's notice flood and there is no public seam
 * onto it; since PHP 8.1 reflection reaches private methods without help.
 */
function invoke_suppressed(WordConversion $conversion, callable $callback): mixed
{
    $method = new ReflectionMethod(WordConversion::class, 'withoutUpstreamPhpWordNotices');

    return $method->invoke($conversion, $callback(...));
}

it('renders markdown into docx bytes', function () {
    $conversion = new WordConversion();

    $docx = $conversion->markdownToDocx(DocxFixtures::SAMPLE_MARKDOWN);

    expect(substr($docx, 0, 2))->toBe('PK');
    expect(strlen($docx))->toBeGreaterThan(1000);
});

it('reads a rendered document back as github-flavoured markdown', function () {
    $conversion = new WordConversion();

    $markdown = $conversion->docxToMarkdown($conversion->markdownToDocx(DocxFixtures::SAMPLE_MARKDOWN));

    expect($markdown)->toContain('# Quarterly report');
    expect($markdown)->toContain('**12%**');
    // The round trip's documented header-row rewrite: the column alignment
    // survives, the emphasis does not.
    expect($markdown)->toContain('| :-- | --: |');
});

it('drops decoration when plain is requested', function () {
    $conversion = new WordConversion();

    $decorated = DocxFixtures::partOf($conversion->markdownToDocx(DocxFixtures::SAMPLE_MARKDOWN), 'word/styles.xml');
    $plain     = DocxFixtures::partOf($conversion->markdownToDocx(DocxFixtures::SAMPLE_MARKDOWN, plain: true), 'word/styles.xml');

    expect($decorated)->toContain('IntenseQuote');
    expect($plain)->not->toContain('IntenseQuote');
});

it('maps the package exception tree onto WordDocumentException', function () {
    $conversion = new WordConversion();

    // `$caught` rather than `$this->fail()` inside try/catch: PHPUnit's
    // failure exception derives from AssertionFailedError, which would be
    // caught by the very `catch` block meant to inspect the real exception.
    $caught = null;

    try {
        $conversion->docxToMarkdown('this is not a zip archive');
    } catch (WordDocumentException $e) {
        $caught = $e;
    }

    // The upstream exception must survive as `$previous`, or the original
    // wording is lost from every log line the ingest pipeline writes.
    expect($caught)->toBeInstanceOf(WordDocumentException::class);
    expect($caught->getPrevious())->toBeInstanceOf(UnreadableDocument::class);
    expect($caught->getMessage())->toContain('Reading the Word document');
});

it('caps the forward direction at 8 MiB and tightens the reverse archive bounds', function () {
    // Tightened from the library's 256 MiB default. The entry and style-depth
    // caps stay at theirs on purpose — they are the zip-bomb and style-loop
    // defences, and the package's guidance is not to relax them.
    expect(WordConversion::MAX_MARKDOWN_BYTES)->toBe(8 * 1024 * 1024);
    expect(WordConversion::MAX_PART_BYTES)->toBe(64 * 1024 * 1024);
    expect(WordConversion::MAX_ARCHIVE_ENTRIES)->toBe(4096);
    expect(WordConversion::MAX_STYLE_DEPTH)->toBe(32);
});

it('refuses markdown beyond the ceiling without handing it to the renderer', function () {
    $conversion = new WordConversion();
    $recorder   = new ErrorRecorder();
    set_error_handler($recorder, E_ALL);

    $caught = null;

    try {
        $conversion->markdownToDocx(str_repeat('a', WordConversion::MAX_MARKDOWN_BYTES + 1));
    } catch (WordDocumentException $e) {
        $caught = $e;
    } finally {
        restore_error_handler();
    }

    expect($caught)->toBeInstanceOf(WordDocumentException::class);
    expect($caught->getMessage())->toContain((string) WordConversion::MAX_MARKDOWN_BYTES);
});

it('swallows the upstream PHPWord deprecation and re-raises everything else', function () {
    $conversion = new WordConversion();
    $recorder   = new ErrorRecorder();
    set_error_handler($recorder, E_ALL);

    try {
        $docx = $conversion->markdownToDocx(DocxFixtures::SAMPLE_MARKDOWN);
    } finally {
        restore_error_handler();
    }

    expect(substr($docx, 0, 2))->toBe('PK');
    // Nothing may reach the kernel's handler: `Kernel::configureErrorHandling()`
    // logs `E_DEPRECATED` at `warning`, so a survivor is a `spora.log` line on
    // every agent turn that writes a document.
    expect($recorder->entries())->toBe([]);
    expect($recorder->phpwordNoticeCount())->toBe(0);
});

it('lets a deprecation from outside phpoffice/phpword through to the handler below', function () {
    $conversion = new WordConversion();
    $recorder   = new ErrorRecorder();
    set_error_handler($recorder, E_ALL);

    try {
        invoke_suppressed($conversion, static function (): void {
            raise_engine_deprecation(1);
        });
    } finally {
        restore_error_handler();
    }

    expect($recorder->entries())->toHaveCount(1);
    expect($recorder->entries()[0]['errno'])->toBe(E_DEPRECATED);
    expect($recorder->entries()[0]['file'])->not->toContain('/phpoffice/phpword/');
});

it('pops its error handler once the render is done', function () {
    $conversion = new WordConversion();
    $recorder   = new ErrorRecorder();
    set_error_handler($recorder, E_ALL);

    try {
        $conversion->markdownToDocx(DocxFixtures::SAMPLE_MARKDOWN);
        // Deliberately unmediated. If the suppressor's frame were still
        // installed it would sit above this recorder and swallow these too —
        // the leak that would silence PHPWord's notices for the rest of the
        // worker's life.
        (new MarkdownToWord())->toDocx(DocxFixtures::SAMPLE_MARKDOWN);
    } finally {
        restore_error_handler();
    }

    expect($recorder->phpwordNoticeCount())->toBeGreaterThan(0);
});

it('pops its error handler when the conversion throws', function () {
    $conversion = new WordConversion();
    $recorder   = new ErrorRecorder();
    set_error_handler($recorder, E_ALL);

    try {
        try {
            $conversion->docxToMarkdown('not a zip');
        } catch (WordDocumentException) {
            // The point is the handler stack afterwards, not the throw.
        }

        (new MarkdownToWord())->toDocx(DocxFixtures::SAMPLE_MARKDOWN);
    } finally {
        restore_error_handler();
    }

    expect($recorder->phpwordNoticeCount())->toBeGreaterThan(0);
});
