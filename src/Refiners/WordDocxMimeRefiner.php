<?php

declare(strict_types=1);

namespace Spora\Plugins\Word\Refiners;

use Spora\Plugins\Word\Services\WordConversion;
use Spora\Services\MediaArchive\MediaMimeRefinerInterface;
use ZipArchive;

/**
 * Promotes a coarse `application/zip` sniff to the DOCX MIME.
 *
 * Load-bearing rather than cosmetic. `MimeSniffer::sniffFromBytes()` feeds
 * `finfo_buffer` a 4 KiB prefix, and whether that is enough for libmagic to
 * name an OOXML package depends on the build: measured on one machine, 4096
 * bytes returns the DOCX MIME and 200 bytes returns `application/zip`.
 * `MimeSniffer::MAGIC_SIGNATURES` cannot close the gap — `PK\x03\x04` is the
 * first local-file-header signature of *every* zip. On a host with old
 * libmagic a DOCX upload is therefore rejected outright with a 415, before
 * this plugin's converter is ever consulted, because the upload allowlist is
 * gated on the sniffed MIME alone.
 *
 * The check is a package member lookup, and that is the whole safety
 * argument: `word/document.xml` is what makes an OOXML package a Word
 * document. Claiming every `PK\x03\x04` blob would relabel xlsx, pptx and
 * epub as Word files and corrupt the allowlist for every other format.
 *
 * No constructor, by contract — `MimeSniffer` instantiates refiners
 * statically so it can keep its own constructor argument-free. Nothing here
 * needs a collaborator, but a future one must stay resolvable this way.
 *
 * Returns `null` rather than throwing for everything it declines, including
 * bytes that are not a zip at all: the interface promises the refiner is
 * pure, and the sniffer walks the next refiner when it gets a `null`.
 */
final class WordDocxMimeRefiner implements MediaMimeRefinerInterface
{
    /**
     * The part every Word document carries, and the only thing this plugin
     * claims a package on.
     */
    private const DOCUMENT_PART = 'word/document.xml';

    /**
     * Coarse container types worth opening. `application/octet-stream` is
     * deliberately absent: it is what `finfo` returns for anything it cannot
     * name, and unzipping an arbitrary blob on its say-so would turn every
     * unrecognised upload into a filesystem probe.
     */
    private const CANDIDATE_SNIFFED_MIMES = ['application/zip', WordConversion::DOCX_MIME];

    public function refine(string $bytes, ?string $filename, string $sniffedMime): ?string
    {
        if (!in_array($sniffedMime, self::CANDIDATE_SNIFFED_MIMES, true)) {
            return null;
        }

        return $this->isWordPackage($bytes) ? WordConversion::DOCX_MIME : null;
    }

    /**
     * Whether the bytes open as a zip archive carrying `word/document.xml`.
     *
     * `ZipArchive::open()` needs a path, so the payload is staged through
     * `tempnam()` and removed on the way out. Opened `RDONLY` — a refiner must
     * never be able to rewrite the archive it is inspecting — and nothing is
     * ever extracted, so no entry name from the untrusted archive ever reaches
     * the filesystem as a path. The whole window, `open()` through
     * `locateName()`, sits inside one scoped error handler: a corrupt central
     * directory warns from `locateName()` too, and a warning reaching the
     * kernel would violate this class's contract of emitting nothing.
     */
    private function isWordPackage(string $bytes): bool
    {
        // Both bounds are decided *before* anything reaches the filesystem.
        //
        // The size bound matters because this runs inside the upload
        // allowlist gate — `MediaUploadController::store()` calls
        // `sniffFromBytes()` on the full body before `checkMimeAllowed()`
        // rejects it — so staging is on the path for every zip a user ever
        // attaches, declined or not, and staging doubles peak `/tmp` per
        // request. There is no application-level upload cap (only PHP's
        // `upload_max_filesize`), so a large body would otherwise be written
        // out in full only to be thrown away a moment later. A chat
        // attachment has no reason to approach the reverse-direction ceiling
        // this plugin already set.
        //
        // The entry bound matters because `locateName()` parses the whole
        // central directory, so a zip bomb's real cost lands in memory here.
        // The upstream reader this mirrors refuses `numFiles > maxEntries`
        // right after opening; matching that is what keeps the refiner from
        // being the weaker of the two defences on the same bytes.
        if ($bytes === '' || strlen($bytes) > WordConversion::MAX_PART_BYTES) {
            return false;
        }

        $staged = tempnam(sys_get_temp_dir(), 'spora-docx-refine-');
        if ($staged === false) {
            return false;
        }

        try {
            if (file_put_contents($staged, $bytes) === false) {
                return false;
            }

            // Scoped to `E_WARNING`, and the displaced handler is kept so a
            // diagnostic from anywhere else in the window still reaches the
            // kernel rather than being swallowed. `WordConversion` holds the
            // same rule for the same reason.
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
                $archive = new ZipArchive();
                $opened  = $archive->open($staged, ZipArchive::RDONLY);
            } finally {
                restore_error_handler();
            }

            if ($opened !== true) {
                return false;
            }

            try {
                if ($archive->numFiles > WordConversion::MAX_ARCHIVE_ENTRIES) {
                    return false;
                }

                return $archive->locateName(self::DOCUMENT_PART) !== false;
            } finally {
                $archive->close();
            }
        } finally {
            // The rename into /tmp already happened; this only cleans up when
            // an earlier step bailed out.
            if (is_file($staged)) {
                unlink($staged);
            }
        }
    }
}
