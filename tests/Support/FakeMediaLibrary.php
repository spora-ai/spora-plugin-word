<?php

declare(strict_types=1);

namespace Spora\Plugins\Office\Tests\Support;

use Spora\Plugins\Office\Finder\MediaLibrarySource;

/**
 * An in-memory media library.
 *
 * The real one reads core's tables, and the folders table is not in every core
 * this plugin installs against — so the finder's own rules are tested here
 * rather than against a database.
 */
final class FakeMediaLibrary implements MediaLibrarySource
{
    /**
     * Principal id to theme name to file name to bytes.
     *
     * @var array<int, array<string, array<string, string>>>
     */
    private array $themes = [];

    /**
     * Principal id to asset filename to bytes.
     *
     * @var array<int, array<string, string>>
     */
    private array $assets = [];

    /**
     * The theme keys the finder asked for, in the order it asked.
     *
     * @var list<string>
     */
    private array $asked = [];

    /**
     * @param array<string, string> $files the theme's files, by name
     */
    public function withTheme(int $principalId, string $theme, array $files): self
    {
        $this->themes[$principalId][$theme] = $files;

        return $this;
    }

    public function withAsset(int $principalId, string $filename, string $bytes): self
    {
        $this->assets[$principalId][$filename] = $bytes;

        return $this;
    }

    /**
     * The theme keys the finder asked the library about.
     *
     * @return list<string>
     */
    public function askedThemes(): array
    {
        return $this->asked;
    }

    /**
     * @return list<string>
     */
    public function themeNames(int $principalId): array
    {
        $names = [];

        foreach ($this->themes[$principalId] ?? [] as $theme => $files) {
            if (isset($files['theme.yml'])) {
                $names[] = $theme;
            }
        }

        sort($names);

        return $names;
    }

    /**
     * @return list<string>
     */
    public function themeFiles(int $principalId, string $theme): array
    {
        $this->asked[] = $theme;

        $files = array_keys($this->themes[$principalId][$theme] ?? []);
        sort($files);

        return $files;
    }

    public function themeFile(int $principalId, string $theme, string $file): ?string
    {
        $this->asked[] = $theme;

        return $this->themes[$principalId][$theme][$file] ?? null;
    }

    public function assetBytes(int $principalId, string $filename): ?string
    {
        return $this->assets[$principalId][$filename] ?? null;
    }
}
