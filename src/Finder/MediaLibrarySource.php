<?php

declare(strict_types=1);

namespace Spora\Plugins\Office\Finder;

/**
 * The media library, as the finder reads it.
 *
 * A seam rather than a reach for core's models: the finder's own rules — what a
 * theme name may be, which manifest the Word half is served — are worth
 * testing without a database, and the tables the finder reads are core's
 * schema rather than a core service.
 *
 * Every method takes the principal it answers for. A finder is built for one
 * principal and passes it down, so a read cannot reach another tenant's rows.
 */
interface MediaLibrarySource
{
    /**
     * The names of the principal's top-level folders that hold a theme manifest.
     *
     * @return list<string>
     */
    public function themeNames(int $principalId): array;

    /**
     * The names of the files in the folder a theme key names.
     *
     * The key is the folder's name or its id. A key that names no top-level
     * folder of the principal has no files.
     *
     * @return list<string>
     */
    public function themeFiles(int $principalId, string $theme): array;

    /**
     * The bytes of one file of the folder a theme key names.
     *
     * Null when the folder is not there or the file is not in it.
     */
    public function themeFile(int $principalId, string $theme, string $file): ?string;

    /**
     * The bytes of the principal's most recent asset with this filename.
     *
     * Null when the principal has no such asset.
     */
    public function assetBytes(int $principalId, string $filename): ?string;
}
