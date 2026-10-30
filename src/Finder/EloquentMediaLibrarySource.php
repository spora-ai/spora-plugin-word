<?php

declare(strict_types=1);

namespace Spora\Plugins\Office\Finder;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use MarkdownWord\Theme\ThemeLoader;
use Spora\Core\Database;
use Spora\Models\MediaAsset;
use Spora\Plugins\Office\Exceptions\OfficeRuntimeException;
use Spora\Plugins\Office\Services\OfficeSourceBytes;

/**
 * The media library as it is stored: core's tables, read directly.
 *
 * The folders are read through the query builder rather than through
 * `Spora\Models\MediaFolder`, because that model is not in every core this
 * plugin installs against and a reference to a class that is not there is a
 * static-analysis failure rather than a runtime one. What is read is the
 * schema — `id`, `principal_id`, `parent_id`, `name` — and that schema is
 * fixed by the folders migration, so the coupling is the columns and nothing
 * else.
 *
 * The assets are read through core's own model, which every core has.
 *
 * A theme is a **top-level** folder: the folder's name is the theme's name,
 * and a folder's id resolves as well, so a task can pin a theme by a value
 * that does not change when the operator renames it.
 */
final class EloquentMediaLibrarySource implements MediaLibrarySource
{
    /**
     * Whether the core this runs against has folders at all.
     *
     * Null until it is asked. The table and the `folder_id` column arrive in
     * the same migration, so the table is the whole question.
     */
    private ?bool $hasFolders = null;

    public function __construct(
        private readonly OfficeSourceBytes $bytes,
    ) {}

    /**
     * @return list<string>
     */
    public function themeNames(int $principalId): array
    {
        $folders = $this->folders($principalId);

        if ($folders === null) {
            return [];
        }

        $names = [];

        foreach ($folders->get(['id', 'name']) as $folder) {
            $names[(string) $folder->id] = (string) $folder->name;
        }

        if ($names === []) {
            return [];
        }

        $manifests = $this->assets($principalId)
            ->whereIn('folder_id', array_keys($names))
            ->where('filename', ThemeLoader::THEME_FILE)
            ->pluck('folder_id')
            ->all();

        $themes = [];

        foreach ($manifests as $folderId) {
            $name = $names[(string) $folderId] ?? null;

            if ($name !== null) {
                $themes[$name] = true;
            }
        }

        $found = array_keys($themes);
        sort($found);

        return $found;
    }

    /**
     * @return list<string>
     */
    public function themeFiles(int $principalId, string $theme): array
    {
        $folderId = $this->folderId($principalId, $theme);

        if ($folderId === null) {
            return [];
        }

        $files = $this->assets($principalId)
            ->where('folder_id', $folderId)
            ->pluck('filename')
            ->all();

        $unique = array_values(array_unique($files));
        sort($unique);

        return $unique;
    }

    public function themeFile(int $principalId, string $theme, string $file): ?string
    {
        $folderId = $this->folderId($principalId, $theme);

        if ($folderId === null) {
            return null;
        }

        $asset = $this->assets($principalId)
            ->where('folder_id', $folderId)
            ->where('filename', $file)
            ->latest('created_at')
            ->first();

        return $asset === null ? null : $this->bytes($asset);
    }

    public function assetBytes(int $principalId, string $filename): ?string
    {
        $asset = $this->assets($principalId)
            ->where('filename', $filename)
            ->latest('created_at')
            ->first();

        return $asset === null ? null : $this->bytes($asset);
    }

    /**
     * The folder a theme key names, by its name or by its id.
     */
    private function folderId(int $principalId, string $theme): ?string
    {
        $folders = $this->folders($principalId);

        if ($folders === null) {
            return null;
        }

        $byName = $folders->where('name', $theme)->first();

        if ($byName !== null) {
            return (string) $byName->id;
        }

        $byId = $this->folders($principalId)?->where('id', $theme)->first();

        return $byId === null ? null : (string) $byId->id;
    }

    /**
     * The principal's top-level folders, or null when the core has no folders.
     */
    private function folders(int $principalId): ?Builder
    {
        if (!$this->hasFolders()) {
            return null;
        }

        return Database::getCapsule()
            ->table('media_folders')
            ->where('principal_id', $principalId)
            ->whereNull('parent_id');
    }

    /**
     * The principal's assets.
     *
     * @return EloquentBuilder<MediaAsset>
     */
    private function assets(int $principalId): EloquentBuilder
    {
        return MediaAsset::query()->where('principal_id', $principalId);
    }

    /**
     * Whether this core has the folders table.
     *
     * A core without it has no media themes, and saying so is the honest
     * answer: a document that names a theme is then drawn in the theme the
     * library itself ships, rather than in a failure nobody asked for.
     */
    private function hasFolders(): bool
    {
        return $this->hasFolders ??= Database::getCapsule()->schema()->hasTable('media_folders');
    }

    /**
     * The bytes of an asset, or null when they cannot be read.
     *
     * Null rather than an exception: a finder answers and never refuses, and a
     * file it cannot read is a file that is not there as far as the library is
     * concerned.
     */
    private function bytes(MediaAsset $asset): ?string
    {
        try {
            return $this->bytes->read($asset, 'EloquentMediaLibrarySource');
        } catch (OfficeRuntimeException) {
            return null;
        }
    }
}
