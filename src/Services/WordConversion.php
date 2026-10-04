<?php

declare(strict_types=1);

namespace Spora\Plugins\Word\Services;

use Closure;
use MarkdownWord\Configuration;
use MarkdownWord\Exception\Exception as MarkdownWordException;
use MarkdownWord\MarkdownToWord;
use MarkdownWord\Reverse\Options;
use MarkdownWord\WordToMarkdown;
use Spora\Plugins\Word\Exceptions\WordDocumentException;

/**
 * The single place both conversion directions go through.
 *
 * Three concerns live here rather than in each producer, because each is only
 * correct when both directions share one copy: the deprecation suppressor has
 * to be paired exactly once per render (two independent copies could
 * interleave `set_error_handler` / `restore_error_handler` and pop each
 * other's frame), the size caps are the plugin's answer to a library that
 * applies no forward-direction limit of its own, and the exception mapping is
 * what lets callers stop knowing PHPWord's exception tree.
 */
final class WordConversion
{
    /**
     * The one place this plugin names the format it both emits and consumes.
     * Both producers stamp it on the derivative they mint or claim it as the
     * MIME they read, and the refiner promotes a coarse `application/zip`
     * verdict to it — a single literal so the three cannot drift apart and
     * leave a DOCX that converts under one name and is served under another.
     */
    public const DOCX_MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    /**
     * Largest Markdown source the renderer will accept.
     *
     * `fabeat/markdown-word` bounds the *reverse* direction (a hostile `.docx`
     * is somebody else's file) but applies no limit here, so without this an
     * oversized generation is the producer's problem to bound. A `media(create_media)`
     * call caps its content at 1 MiB upstream, so 8 MiB is generous for every
     * legitimate document while still refusing to hand an unbounded string to
     * a parser that holds the whole syntax tree in memory at once.
     */
    public const MAX_MARKDOWN_BYTES = 8 * 1024 * 1024;

    /**
     * Tightened from the upstream 256 MiB default. The reverse direction runs
     * on chat attachments — a document an agent was asked to read — not on a
     * file server, so a quarter-gigabyte decompression budget buys nothing and
     * lets a zip bomb dominate a worker's memory.
     */
    public const MAX_PART_BYTES = 64 * 1024 * 1024;

    /**
     * Largest number of parts the archive may carry, and how far a `basedOn`
     * style chain is followed. Both kept at the upstream values on purpose:
     * the first is zip-bomb hardening and the second stops a document making
     * its own style graph a loop. `fabeat/markdown-word`'s own guidance is
     * explicit that they are not to be relaxed.
     */
    public const MAX_ARCHIVE_ENTRIES = 4096;

    public const MAX_STYLE_DEPTH = 32;

    /**
     * Path fragment that identifies the one upstream file whose notices this
     * plugin silences. See {@see self::withoutUpstreamPhpWordNotices()}.
     */
    private const PHPWORD_SOURCE_MARKER = '/phpoffice/phpword/';

    /**
     * Render Markdown to the raw bytes of a `.docx`.
     *
     * `$plain` drops everything this library adds over Word's own styles — the
     * code and link fonts, the quote style, table borders and code-block
     * shading. It is the *only* knob v1 exposes on purpose: the rest of
     * `MarkdownWord\Configuration` is a template-authoring surface, and handing
     * it to an LLM through `create_derivative`'s free-form `options` blob would
     * buy no capability a rendered document needs.
     *
     * @throws WordDocumentException when the source breaches
     *         {@see MAX_MARKDOWN_BYTES} or the renderer refuses the input.
     */
    public function markdownToDocx(string $markdown, bool $plain = false): string
    {
        if (strlen($markdown) > self::MAX_MARKDOWN_BYTES) {
            throw new WordDocumentException(sprintf(
                'Markdown source of %d bytes exceeds the %d-byte ceiling for a Word render.',
                strlen($markdown),
                self::MAX_MARKDOWN_BYTES,
            ));
        }

        $configuration = $plain
            ? (new Configuration())->withoutDecoration()
            : new Configuration();

        try {
            return $this->withoutUpstreamPhpWordNotices(
                static fn(): string => (new MarkdownToWord(null, $configuration))->toDocx($markdown),
            );
        } catch (MarkdownWordException $e) {
            // `toDocx()` declares four upstream exceptions and this is the
            // direction where they actually happen — `FileNotWritable` when
            // the archive cannot be staged, `UnreadableDocument` when a pass
            // cannot reopen it, `MalformedDocument` when a part is not XML.
            // Letting them escape breaks the hierarchy every caller is
            // promised (`WordRuntimeException` is documented as the base for
            // everything this plugin reports) and surfaces a vendor class
            // name to the LLM, which matters most on exactly the
            // constrained shared hosts the README's platform-requirement
            // section is written for.
            throw WordDocumentException::fromMarkdownWord('Rendering the Word document', $e);
        }
    }

    /**
     * Read a `.docx` back into GitHub-Flavored Markdown.
     *
     * The upstream `UnreadableDocument` / `MalformedDocument` are deliberately
     * not translated into a blank string: {@see \Spora\Plugins\Word\Producers\DocxToMarkdownProducer}
     * lets them surface as a {@see WordDocumentException} and leaves the
     * decision to its caller, which is the graceful degradation we want — a
     * hostile document cannot fail an upload, and the chat falls back to the
     * metadata block.
     *
     * `mediaDirectory` stays null. A `.docx` read into chat should not leave
     * extracted images behind on disk.
     *
     * @throws WordDocumentException when the bytes are not a readable document.
     */
    public function docxToMarkdown(string $bytes): string
    {
        $options = new Options(
            mediaDirectory: null,
            maxPartBytes: self::MAX_PART_BYTES,
            maxEntries: self::MAX_ARCHIVE_ENTRIES,
            maxStyleDepth: self::MAX_STYLE_DEPTH,
        );

        try {
            return $this->withoutUpstreamPhpWordNotices(
                static fn(): string => (new WordToMarkdown(null, $options))->toMarkdown($bytes),
            );
        } catch (MarkdownWordException $e) {
            throw WordDocumentException::fromMarkdownWord('Reading the Word document', $e);
        }
    }

    /**
     * Run `$callback` with `phpoffice/phpword`'s own deprecation silenced.
     *
     * PHPWord 1.4's `Style::getStyle()` is called with a null name while
     * writing any paragraph that carries no numbering of its own — every list
     * item, so any real document — and raises
     * `Using null as an array offset is deprecated`. That is roughly fifteen
     * notices for a two-paragraph file on PHP 8.5. The output is correct and
     * nothing reachable from the library's API avoids it; the package silences
     * it for its own CLI and test harness and documents that embedding leaves
     * it visible. Spora's `Kernel::configureErrorHandling()` routes
     * `E_DEPRECATED` to the logger at `warning`, so in dev every render would
     * write fifteen warning lines into `spora.log`.
     *
     * The suppressor is scoped to the exact level and the exact vendor
     * directory: the `E_DEPRECATED` mask stops the callback running for
     * anything else, and the path check stops it hiding a notice raised by
     * this plugin or by anything else in the same process. Anything the
     * callback declines is passed to the handler that was displaced — NOT
     * returned as `false`, which would restore PHP's *built-in* handler and
     * print raw text straight to the error log instead of reaching Spora's
     * `Kernel::configureErrorHandling()` and its `warning`-level entry. The
     * `finally` pops our
     * frame even when the conversion throws, so a failure cannot leave the
     * suppressor installed for the rest of the worker's life.
     *
     * Same scoped `set_error_handler` / `restore_error_handler` pairing core
     * already uses at
     * `app/Services/MediaArchive/Producers/ImageDerivativeProducer.php:234`
     * and `app/Services/MediaArchive/MetadataExtractor.php:151`; PHP 8.4 no
     * longer honours `@` for these calls, so the explicit handler is the only
     * way to keep a suppressed read from becoming a warning.
     */
    private function withoutUpstreamPhpWordNotices(Closure $callback): mixed
    {
        $previous = set_error_handler(
            static function (int $errno, string $errstr, string $errfile, int $errline) use (&$previous): bool {
                if ($errno === E_DEPRECATED && str_contains($errfile, self::PHPWORD_SOURCE_MARKER)) {
                    return true;
                }

                // Returning false would restore PHP's *built-in* handler and
                // print straight to the error log, not resume the caller's:
                // `set_error_handler` has no chaining. So a deprecation from
                // plugin code raised mid-conversion would bypass Spora's
                // Kernel handler (which logs at `warning`) and dump raw text.
                // Delegate explicitly instead.
                if ($previous !== null) {
                    return $previous($errno, $errstr, $errfile, $errline);
                }

                return false;
            },
            E_DEPRECATED,
        );

        try {
            return $callback();
        } finally {
            restore_error_handler();
        }
    }
}
