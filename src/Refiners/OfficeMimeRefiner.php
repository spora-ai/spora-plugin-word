<?php

declare(strict_types=1);

namespace Spora\Plugins\Office\Refiners;

use Spora\Plugins\Office\Services\OfficeConversion;
use Spora\Services\MediaArchive\MediaMimeRefinerInterface;
use ZipArchive;

/**
 * Promotes a coarse `application/zip` sniff to the MIME of the Office package
 * it holds.
 *
 * Load-bearing rather than cosmetic. `MimeSniffer::sniffFromBytes()` feeds
 * `finfo_buffer` a 4 KiB prefix, and whether that is enough for libmagic to
 * name an OOXML package depends on the build: measured on one machine, 4096
 * bytes returns the DOCX MIME and 200 bytes returns `application/zip`.
 * `MimeSniffer::MAGIC_SIGNATURES` cannot close the gap — `PK\x03\x04` is the
 * first local-file-header signature of *every* zip. On a host with old
 * libmagic a DOCX upload is therefore rejected outright with a 415, before
 * this plugin's producers are ever consulted, because the upload allowlist is
 * gated on the sniffed MIME alone.
 *
 * The check is a package member lookup, and that is the whole safety
 * argument: `word/document.xml` is what makes an OOXML package a Word
 * document and `ppt/presentation.xml` is what makes one a deck. Claiming every
 * `PK\x03\x04` blob would relabel xlsx and epub as Office files and corrupt
 * the allowlist for every other format.
 *
 * No constructor, by contract — `MimeSniffer` instantiates refiners
 * statically so it can keep its own constructor argument-free. Nothing here
 * needs a collaborator, but a future one must stay resolvable this way.
 *
 * Returns `null` rather than throwing for everything it declines, including
 * bytes that are not a zip at all: the interface promises the refiner is
 * pure, and the sniffer walks the next refiner when it gets a `null`.
 */
final class OfficeMimeRefiner implements MediaMimeRefinerInterface
{
    /**
     * The part each Office package carries, and the only thing this plugin
     * claims a package on.
     *
     * @var array<string, string>
     */
    private const PACKAGE_PARTS = [
        'word/document.xml'    => OfficeConversion::DOCX_MIME,
        'ppt/presentation.xml' => OfficeConversion::PPTX_MIME,
    ];

    /**
     * Coarse container types worth opening. `application/octet-stream` is
     * deliberately absent: it is what `finfo` returns for anything it cannot
     * name, and unzipping an arbitrary blob on its say-so would turn every
     * unrecognised upload into a filesystem probe.
     */
    private const CANDIDATE_SNIFFED_MIMES = [
        'application/zip',
        OfficeConversion::DOCX_MIME,
        OfficeConversion::PPTX_MIME,
    ];

    public function refine(string $bytes, ?string $filename, string $sniffedMime): ?string
    {
        if (!in_array($sniffedMime, self::CANDIDATE_SNIFFED_MIMES, true)) {
            return null;
        }

        return $this->officePackageMime($bytes);
    }

    /**
     * The MIME of the Office package the bytes hold, or null when they hold
     * no Office package at all.
     */
    private function officePackageMime(string $bytes): ?string
    {
        if ($bytes === '' || strlen($bytes) > OfficeConversion::MAX_PART_BYTES) {
            return null;
        }

        return $this->withStagedArchive($bytes, $this->packageMime(...));
    }

    /**
     * `ZipArchive::open()` takes a path, so the payload has to exist on disk to
     * be opened at all — which is the only reason this method exists. It is
     * separate from {@see self::officePackageMime()} so the decision reads as a
     * decision instead of as bail-out branches interleaved with temp-file
     * bookkeeping.
     *
     * @param callable(string): ?string $inspect
     */
    private function withStagedArchive(string $bytes, callable $inspect): ?string
    {
        $staged = tempnam(sys_get_temp_dir(), 'spora-office-refine-');

        if ($staged === false) {
            return null;
        }

        try {
            if (file_put_contents($staged, $bytes) === false) {
                return null;
            }

            return $this->mutingArchiveWarnings(static fn(): ?string => $inspect($staged));
        } finally {
            if (is_file($staged)) {
                @unlink($staged);
            }
        }
    }

    /**
     * The scoped handler wraps `open()` and `locateName()` together rather
     * than each on its own: a corrupt central directory warns from the
     * latter, and this class's contract is that it emits nothing.
     */
    private function packageMime(string $path): ?string
    {
        $archive = new ZipArchive();

        // The `try` starts only once the archive is open: `ZipArchive::close()`
        // on a handle that never opened throws a ValueError, which would turn
        // "this is not a zip" — the single most common answer here — into an
        // exception escaping the refiner.
        if ($archive->open($path, ZipArchive::RDONLY) !== true) {
            return null;
        }

        try {
            return $this->officePartMime($archive);
        } finally {
            $archive->close();
        }
    }

    /**
     * The MIME of the Office part the archive carries, or null when it
     * carries none.
     *
     * Split out so {@see self::packageMime()} stays under the 3-return
     * brain-overload ceiling: opening the archive and naming the part inside
     * it are two decisions, and the entry-count cap is a third.
     */
    private function officePartMime(ZipArchive $archive): ?string
    {
        if ($archive->numFiles > OfficeConversion::MAX_ARCHIVE_ENTRIES) {
            return null;
        }

        foreach (self::PACKAGE_PARTS as $part => $mime) {
            if ($archive->locateName($part) !== false) {
                return $mime;
            }
        }

        return null;
    }

    /**
     * Swallow `E_WARNING` for the duration of `$callback` and pass anything
     * else to the handler that was displaced.
     *
     * The mask is `E_WARNING` rather than `E_ALL` on purpose: a blanket
     * handler would swallow an unrelated diagnostic raised in the same
     * window, and `return false` is not the way to delegate either — that
     * restores PHP's *built-in* handler and prints raw text instead of
     * reaching Spora's `Kernel::configureErrorHandling()`.
     * {@see OfficeConversion} holds the same rule
     * for the same reason.
     *
     * @template T
     *
     * @param  callable(): T $callback
     * @return T
     */
    private function mutingArchiveWarnings(callable $callback): mixed
    {
        $previous = set_error_handler(
            static function (int $errno) use (&$previous): bool {
                if ($errno === E_WARNING) {
                    return true;
                }

                return $previous !== null && $previous(...func_get_args());
            },
            E_WARNING,
        );

        try {
            return $callback();
        } finally {
            restore_error_handler();
        }
    }
}
