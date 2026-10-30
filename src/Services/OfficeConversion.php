<?php

declare(strict_types=1);

namespace Spora\Plugins\Office\Services;

use Closure;
use MarkdownPresentation\Document\DeckParser;
use MarkdownPresentation\Exception\Exception as MarkdownPresentationException;
use MarkdownPresentation\PowerPointToMarkdown;
use MarkdownPresentation\Render\PresentationRenderer;
use MarkdownPresentation\Reverse\Options as PresentationOptions;
use MarkdownPresentation\Theme\ThemeResolver as PresentationThemeResolver;
use MarkdownWord\Configuration;
use MarkdownWord\Exception\Exception as MarkdownWordException;
use MarkdownWord\MarkdownToWord;
use MarkdownWord\Parser\CommonMarkParser;
use MarkdownWord\Reverse\Options as WordOptions;
use MarkdownWord\WordToMarkdown;
use Spora\Plugins\Office\Exceptions\OfficeDocumentException;
use Spora\Plugins\Office\Exceptions\OfficeRuntimeException;
use Spora\Plugins\Office\Finder\LibraryHalf;
use Spora\Plugins\Office\Finder\MediaLibraryFinder;
use Spora\Plugins\Office\Finder\MediaLibrarySource;

/**
 * The one place the plugin's conversions go through — both directions, and
 * both halves of `markdown-office`.
 *
 * The suppressor is why they share this class: a second copy could interleave
 * `set_error_handler` with `restore_error_handler` and pop this frame.
 */
final class OfficeConversion
{
    /**
     * The one place this plugin names the formats it both emits and consumes.
     */
    public const DOCX_MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    public const ODT_MIME = 'application/vnd.oasis.opendocument.text';

    public const RTF_MIME = 'application/rtf';

    public const PPTX_MIME = 'application/vnd.openxmlformats-officedocument.presentationml.presentation';

    public const MARKDOWN_MIME = 'text/markdown';

    /**
     * Largest Markdown source the renderer will accept.
     *
     * The libraries bound the *reverse* direction (a hostile `.docx` is somebody
     * else's file) but apply no limit here, so without this an oversized
     * generation is the producer's problem to bound. A `media(create_media)`
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
     * its own style graph a loop.
     */
    public const MAX_ARCHIVE_ENTRIES = 4096;

    public const MAX_STYLE_DEPTH = 32;

    /**
     * Path fragment that identifies the one upstream file whose notices this
     * plugin silences. See {@see self::withoutUpstreamPhpWordNotices()}.
     */
    private const PHPWORD_SOURCE_MARKER = '/phpoffice/phpword/';

    public function __construct(
        private readonly MediaLibrarySource $library,
    ) {}

    /**
     * Markdown as the bytes of a `.docx`.
     *
     * `$plain` drops everything the library adds over Word's own styles — the
     * code and link fonts, the quote style, table borders and code-block
     * shading. It is the *only* knob exposed on purpose: the rest of
     * `MarkdownWord\Configuration` is a template-authoring surface, and handing
     * it to an LLM through `create_derivative`'s free-form `options` blob would
     * buy no capability a rendered document needs.
     *
     * @throws OfficeDocumentException when the source breaches
     *         {@see MAX_MARKDOWN_BYTES} or the renderer refuses the input.
     */
    public function markdownToDocx(
        string $markdown,
        ?int $principalId = null,
        ?string $theme = null,
        bool $plain = false,
    ): string {
        return $this->word($markdown, $principalId, $theme, $plain, static fn(MarkdownToWord $c, string $m): string => $c->toDocx($m));
    }

    /**
     * Markdown as the bytes of an `.odt`.
     *
     * @throws OfficeDocumentException when the source breaches
     *         {@see MAX_MARKDOWN_BYTES} or the renderer refuses the input.
     */
    public function markdownToOdt(
        string $markdown,
        ?int $principalId = null,
        ?string $theme = null,
        bool $plain = false,
    ): string {
        return $this->word($markdown, $principalId, $theme, $plain, static fn(MarkdownToWord $c, string $m): string => $c->toOdt($m));
    }

    /**
     * Markdown as the bytes of an `.rtf`.
     *
     * @throws OfficeDocumentException when the source breaches
     *         {@see MAX_MARKDOWN_BYTES} or the renderer refuses the input.
     */
    public function markdownToRtf(
        string $markdown,
        ?int $principalId = null,
        ?string $theme = null,
        bool $plain = false,
    ): string {
        return $this->word($markdown, $principalId, $theme, $plain, static fn(MarkdownToWord $c, string $m): string => $c->toRtf($m));
    }

    /**
     * A `.docx` as Markdown.
     *
     * `mediaDirectory` stays null. A `.docx` read into chat should not leave
     * extracted images behind on disk.
     *
     * @throws OfficeDocumentException when the bytes are not a readable document.
     */
    public function officeToMarkdown(string $bytes): string
    {
        $options = new WordOptions(
            mediaDirectory: null,
            maxPartBytes: self::MAX_PART_BYTES,
            maxEntries: self::MAX_ARCHIVE_ENTRIES,
            maxStyleDepth: self::MAX_STYLE_DEPTH,
        );

        try {
            return (string) $this->withoutUpstreamPhpWordNotices(
                static fn(): string => (new WordToMarkdown(null, $options))->toMarkdown($bytes),
            );
        } catch (MarkdownWordException $e) {
            throw OfficeDocumentException::fromUpstream('Reading the Word document', $e);
        }
    }

    /**
     * Markdown as the bytes of a `.pptx`.
     *
     * @throws OfficeDocumentException when the source breaches
     *         {@see MAX_MARKDOWN_BYTES} or the renderer refuses the deck.
     */
    public function markdownToPresentation(string $markdown, ?int $principalId = null, ?string $theme = null): string
    {
        $this->assertMarkdownSize($markdown);

        try {
            return $this->withScratch(function (string $scratch) use ($markdown, $principalId, $theme): string {
                $finder = $this->finder($principalId, LibraryHalf::Slides);

                $renderer = new PresentationRenderer(
                    directory: $scratch,
                    themes: new PresentationThemeResolver(workingDirectory: $scratch, files: $finder),
                    files: $finder,
                );

                if ($theme !== null) {
                    $renderer->withTheme($theme);
                }

                return $renderer->toString((new DeckParser())->parse($markdown));
            });
        } catch (MarkdownPresentationException $e) {
            throw OfficeDocumentException::fromUpstream('Rendering the presentation', $e);
        }
    }

    /**
     * A `.pptx` as Markdown.
     *
     * @throws OfficeDocumentException when the bytes are not a readable deck.
     */
    public function presentationToMarkdown(string $bytes): string
    {
        $options = new PresentationOptions(
            mediaDirectory: null,
            maxPartBytes: self::MAX_PART_BYTES,
            maxEntries: self::MAX_ARCHIVE_ENTRIES,
        );

        try {
            return (new PowerPointToMarkdown(null, $options))->toMarkdown($bytes);
        } catch (MarkdownPresentationException $e) {
            throw OfficeDocumentException::fromUpstream('Reading the presentation', $e);
        }
    }

    /**
     * A Word document, written by `$write`.
     *
     * @param callable(MarkdownToWord, string): string $write
     *
     * @throws OfficeDocumentException when the source is oversized or unreadable.
     */
    private function word(string $markdown, ?int $principalId, ?string $theme, bool $plain, callable $write): string
    {
        $this->assertMarkdownSize($markdown);

        try {
            return $this->withScratch(function (string $scratch) use ($markdown, $principalId, $theme, $plain, $write): string {
                $converter = $this->wordConverter($scratch, $principalId, $theme, $plain);

                return (string) $this->withoutUpstreamPhpWordNotices(
                    static fn(): string => $write($converter, $markdown),
                );
            });
        } catch (MarkdownWordException $e) {
            // Letting the upstream exceptions escape breaks the hierarchy every
            // caller is promised (`OfficeRuntimeException` is documented as the
            // base for everything this plugin reports) and surfaces a vendor
            // class name to the LLM.
            throw OfficeDocumentException::fromUpstream('Rendering the Word document', $e);
        }
    }

    /**
     * The Word converter, with the parser that reads a frontmatter and the
     * finder that answers for the media library.
     *
     * @throws MarkdownWordException when the theme named in code does not resolve.
     */
    private function wordConverter(string $scratch, ?int $principalId, ?string $theme, bool $plain): MarkdownToWord
    {
        $converter = new MarkdownToWord(
            null,
            $this->wordConfiguration($scratch, $plain),
            CommonMarkParser::withFrontMatter(),
            null,
            false,
            $scratch,
            $this->finder($principalId, LibraryHalf::Word),
        );

        if ($theme !== null) {
            $converter->withTheme($theme);
        }

        return $converter;
    }

    /**
     * The configuration the Word half is rendered with.
     *
     * `imageBasePath` is the empty directory of the render, so a document that
     * names a picture by a relative path resolves it against a directory that
     * holds nothing rather than against the working directory of the host —
     * which in a Spora installation is the application root, where a file named
     * `config.php` is waiting to be embedded in a document. A document's own
     * frontmatter may still name a base path of its own; this is the default
     * it starts from.
     */
    private function wordConfiguration(string $scratch, bool $plain): Configuration
    {
        $configuration = $plain
            ? (new Configuration())->withoutDecoration()
            : new Configuration();

        return $configuration->withOptions(['imageBasePath' => $scratch]);
    }

    /**
     * The finder the media library is read through, or null when there is no
     * principal to read it for.
     *
     * Null rather than a finder that answers nothing: the libraries then look
     * for a theme in the directories they ship, which is what a document with
     * no owner should be drawn in.
     */
    private function finder(?int $principalId, LibraryHalf $half): ?MediaLibraryFinder
    {
        if ($principalId === null || $principalId <= 0) {
            return null;
        }

        return new MediaLibraryFinder($this->library, $principalId, $half);
    }

    /**
     * @throws OfficeDocumentException when the source breaches {@see MAX_MARKDOWN_BYTES}.
     */
    private function assertMarkdownSize(string $markdown): void
    {
        if (strlen($markdown) > self::MAX_MARKDOWN_BYTES) {
            throw new OfficeDocumentException(sprintf(
                'Markdown source of %d bytes exceeds the %d-byte ceiling for a document render.',
                strlen($markdown),
                self::MAX_MARKDOWN_BYTES,
            ));
        }
    }

    /**
     * Run `$callback` with an empty directory of its own.
     *
     * Both libraries fall back to the file system when a finder has no answer
     * for a name, and the fallback is resolved against a directory the caller
     * names. The directory is empty, so the fallback finds nothing and a
     * missing picture becomes its alt text rather than a file of the host's.
     *
     * The name is random and the mode is private, because a directory that
     * other accounts on a shared host can write into is a directory they can
     * put a file into.
     *
     * @template T
     *
     * @param  callable(string): T $callback
     * @return T
     */
    private function withScratch(callable $callback): mixed
    {
        $scratch = sys_get_temp_dir() . '/spora-office-' . bin2hex(random_bytes(8));

        if (!mkdir($scratch, 0o700, true)) {
            throw new OfficeRuntimeException(sprintf(
                'Unable to create the render directory "%s".',
                $scratch,
            ));
        }

        try {
            return $callback($scratch);
        } finally {
            // The libraries stage into the system temporary directory rather
            // than into this one, so it is empty and one call is enough.
            @rmdir($scratch);
        }
    }

    /**
     * Run `$callback` with `phpoffice/phpword`'s own deprecation silenced.
     *
     * PHPWord 1.4's `Style::getStyle()` is called with a null name while
     * writing any paragraph that carries no numbering of its own — every list
     * item, so any real document — and raises
     * `Using null as an array offset is deprecated`. The output is correct and
     * nothing reachable from the library's API avoids it; the package silences
     * it for its own CLI and test harness and documents that embedding leaves
     * it visible. Spora's `Kernel::configureErrorHandling()` routes
     * `E_DEPRECATED` to the logger at `warning`, so every render would write
     * a warning line per paragraph into `spora.log`.
     *
     * The suppressor is scoped to the exact level and the exact vendor
     * directory: the `E_DEPRECATED` mask stops the callback running for
     * anything else, and the path check stops it hiding a notice raised by
     * this plugin or by anything else in the same process. The `finally` pops
     * our frame even when the conversion throws, so a failure cannot leave the
     * suppressor installed for the rest of the worker's life.
     *
     * PHP 8.4 no longer honours `@` for these calls, so the explicit handler
     * is the only way to keep a suppressed read from becoming a warning. Core
     * uses the same scoped pairing in
     * `app/Services/MediaArchive/Producers/ImageDerivativeProducer.php` and
     * `app/Services/MediaArchive/MetadataExtractor.php`.
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
