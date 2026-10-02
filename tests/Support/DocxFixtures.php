<?php

declare(strict_types=1);

namespace Spora\Plugins\Word\Tests\Support;

use MarkdownWord\Configuration;
use MarkdownWord\MarkdownToWord;
use RuntimeException;
use ZipArchive;

/**
 * Builds the binary fixtures the suite needs at run time.
 *
 * `.docx` files are generated with `MarkdownToWord` rather than committed to
 * the repository: a checked-in binary cannot be reviewed in a diff, cannot be
 * regenerated when the upstream library changes how it writes an archive, and
 * `git` will happily mangle it. Rendering the same Markdown through the same
 * library the plugin calls gives a fixture that is a *real* document by
 * construction — the same approach spora-plugin-typst takes for its `.typ`
 * fixtures.
 */
final class DocxFixtures
{
    /**
     * Exercises every branch that matters downstream: a heading (style lookup),
     * a bold run, a list (the `Style::getStyle()` deprecation source) and a
     * table (the round trip's documented header-bold rewrite).
     */
    public const SAMPLE_MARKDOWN = <<<'MARKDOWN'
        # Quarterly report

        Revenue grew **12%** against a flat market.

        - North grew
        - South held

        | Region | Total |
        | --- | ---: |
        | North | 1,200 |
        MARKDOWN;

    /**
     * Render `$markdown` into the bytes of a `.docx`.
     */
    public static function docx(string $markdown = self::SAMPLE_MARKDOWN, bool $plain = false): string
    {
        $configuration = $plain
            ? (new Configuration())->withoutDecoration()
            : new Configuration();

        // v0.1.0 exposes `withoutDecoration()` as an instance method — the
        // static spelling in the plan predates the immutable-Configuration
        // refactor and fatals against the pinned release.
        return (new MarkdownToWord(null, $configuration))->toDocx($markdown);
    }

    /**
     * A zip that is deliberately not a Word document — the shape an xlsx or
     * pptx upload has, and the case {@see \Spora\Plugins\Word\Refiners\WordDocxMimeRefiner}
     * must decline rather than mislabel.
     *
     * @param array<string, string> $parts
     */
    public static function zipContaining(array $parts): string
    {
        $path = tempnam(sys_get_temp_dir(), 'spora-docx-fixture-');
        if ($path === false) {
            throw new RuntimeException('Could not stage a fixture file.');
        }
        // `tempnam()` leaves an empty file, and opening an empty file with
        // ZipArchive is itself deprecated on PHP 8.5. Remove it first so the
        // archive is created rather than adopted.
        unlink($path);

        try {
            $archive = new ZipArchive();
            if ($archive->open($path, ZipArchive::CREATE) !== true) {
                throw new RuntimeException('Could not create the fixture zip at ' . $path);
            }
            foreach ($parts as $name => $contents) {
                $archive->addFromString($name, $contents);
            }
            $archive->close();

            $bytes = file_get_contents($path);
            if (!is_string($bytes)) {
                throw new RuntimeException('Could not read back the fixture zip at ' . $path);
            }

            return $bytes;
        } finally {
            @unlink($path);
        }
    }

    /**
     * A `.docx` cut short after its first entries: `finfo` still names it a
     * Word document from the local file header, so it reaches the converter
     * and fails there — the case that must degrade to a NULL
     * `markdown_content` rather than fail the upload.
     */
    public static function truncatedDocx(): string
    {
        return substr(self::docx(), 0, 900);
    }

    /**
     * Read one part out of a `.docx` held in memory, or null when the archive
     * does not carry it. Lets a test assert on what the *document* contains
     * (its styles) rather than on the opaque bytes.
     */
    public static function partOf(string $docx, string $part): ?string
    {
        $path = self::stage($docx);
        try {
            $archive = new ZipArchive();
            if ($archive->open($path, ZipArchive::RDONLY) !== true) {
                return null;
            }
            try {
                $contents = $archive->getFromName($part);

                return is_string($contents) ? $contents : null;
            } finally {
                $archive->close();
            }
        } finally {
            @unlink($path);
        }
    }

    private static function stage(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'spora-docx-fixture-');
        if ($path === false) {
            throw new RuntimeException('Could not stage a fixture file.');
        }
        if (file_put_contents($path, $bytes) === false) {
            throw new RuntimeException('Could not write a fixture file to ' . $path);
        }

        return $path;
    }
}
