<?php

declare(strict_types=1);

namespace Spora\Plugins\Office\Finder;

use MarkdownPresentation\Files\FileFinder as PresentationFileFinder;
use MarkdownWord\Files\FileFinder as WordFileFinder;
use MarkdownWord\Theme\Theme;
use MarkdownWord\Theme\ThemeLoader;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Where the files a document names come from: the media library.
 *
 * One class for both halves of `markdown-office`, because the two upstream
 * interfaces are the same shape and the three roles — `theme`, `picture`,
 * `document` — carry the same names in both.
 *
 * **This is a trust boundary.** The name a document carries was written by
 * whoever wrote the document, and the finder answers it with bytes. Two rules
 * keep the answer inside the principal that asked:
 *
 *   - the principal is fixed when the finder is built, and every read is
 *     scoped to it;
 *   - a theme is a folder *name* and never a path, so `theme: ../other-tenant`
 *     names no folder of this principal and resolves to nothing.
 *
 * `path()` answers null always. The media library holds bytes and no files,
 * and a finder that answers a path has that path held to the render's scope;
 * one that answers bytes is trusted, and the library stages what it cannot be
 * handed as bytes into a file of its own.
 *
 * **The Word half is served a projection of the manifest.** The two halves read
 * the same `theme.yml` and disagree about what it may carry: the slides half
 * accepts colours, fonts and a type scale, and the Word half refuses a key it
 * does not know. A folder therefore holds the slides manifest — the superset —
 * and the Word half is handed the three keys it reads ({@see Theme::KEYS}).
 * That projection is the one lossy step in the whole arrangement, and it is
 * here, in one place, on purpose.
 */
final class MediaLibraryFinder implements WordFileFinder, PresentationFileFinder
{
    /**
     * @param MediaLibrarySource $library    the media library, read for one principal
     * @param int                $principalId the principal every answer is scoped to
     * @param LibraryHalf        $half        which half of the library is asking
     */
    public function __construct(
        private readonly MediaLibrarySource $library,
        private readonly int $principalId,
        private readonly LibraryHalf $half,
    ) {}

    /**
     * The bytes of a file a name identifies, or null when there is none.
     *
     * The role says which world the name is resolved in: a `theme` name is
     * `<theme>/<file>` and resolves against a folder of the principal's, and a
     * `picture` or a `document` name is a filename and resolves against an
     * asset of the principal's. A name with no role is an asset — the slides
     * half asks for its templates file that way.
     */
    public function read(string $name, ?string $role = null): ?string
    {
        if ($role === 'theme') {
            return $this->themeFile($name);
        }

        if ($role === 'picture' || $role === 'document' || $role === null) {
            return $this->asset($name);
        }

        return null;
    }

    /**
     * Always null: the media library holds bytes and no files.
     */
    public function path(string $name, ?string $role = null): ?string
    {
        return null;
    }

    /**
     * The themes the principal holds: its top-level folders that carry a
     * manifest.
     *
     * @return list<string>
     */
    public function themes(): array
    {
        return $this->library->themeNames($this->principalId);
    }

    /**
     * The names of the files of a theme.
     *
     * A theme name that is not a folder name has no files: the name is what a
     * document wrote, and a path is not a folder.
     *
     * @return list<string>
     */
    public function files(string $theme): array
    {
        if (!self::isSegment($theme)) {
            return [];
        }

        return $this->library->themeFiles($this->principalId, $theme);
    }

    /**
     * A file of a theme, named `<theme>/<file>`.
     *
     * The theme part is a folder name and the file part is a name inside it.
     * A name that is not that shape names nothing: the library composes the
     * name from the theme a document asked for, and a document that asked for
     * `../other-tenant` must not reach another tenant's theme.
     */
    private function themeFile(string $name): ?string
    {
        $slash = strpos($name, '/');

        if ($slash === false) {
            return null;
        }

        $theme = substr($name, 0, $slash);
        $file  = substr($name, $slash + 1);

        if (!self::isSegment($theme) || !self::isSegment($file)) {
            return null;
        }

        $bytes = $this->library->themeFile($this->principalId, $theme, $file);

        if ($bytes === null) {
            return null;
        }

        if ($this->half === LibraryHalf::Word && $file === ThemeLoader::THEME_FILE) {
            return $this->project($bytes) ?? $bytes;
        }

        return $bytes;
    }

    /**
     * An asset of the principal, named by its filename.
     */
    private function asset(string $name): ?string
    {
        if (!self::isSegment($name)) {
            return null;
        }

        return $this->library->assetBytes($this->principalId, $name);
    }

    /**
     * The manifest the Word half may read, or null when the manifest is not
     * one the slides half would have written either.
     *
     * Null rather than a guess: a manifest that is not a mapping, or is not
     * YAML at all, is the library's to complain about, and it complains with
     * better words than this plugin has. The raw bytes are served in that case
     * so that the complaint is about the manifest and not about the projection.
     */
    private function project(string $manifest): ?string
    {
        try {
            $parsed = Yaml::parse($manifest);
        } catch (ParseException) {
            return null;
        }

        if (!is_array($parsed) || ($parsed !== [] && array_is_list($parsed))) {
            return null;
        }

        $projected = array_intersect_key($parsed, array_flip(Theme::KEYS));

        return Yaml::dump($projected);
    }

    /**
     * Whether a name is a single path segment and nothing else.
     *
     * A name with a separator in it is a path, and a path is not something a
     * folder or a filename is. `.` and `..` are paths as well, and a name that
     * is one of them is a document reaching for the directory it was written
     * from rather than for a folder of its own.
     */
    private static function isSegment(string $name): bool
    {
        return $name !== ''
            && $name !== '.'
            && $name !== '..'
            && !str_contains($name, '/')
            && !str_contains($name, '\\')
            && !str_contains($name, "\0");
    }
}
