<?php

declare(strict_types=1);

namespace Spora\Plugins\Word\Services;

use Spora\Models\MediaAsset;
use Spora\Plugins\Word\Exceptions\WordDocumentException;
use Spora\Plugins\Word\Exceptions\WordRuntimeException;
use Spora\Services\AssetStorageException;
use Spora\Services\DatabaseAssetStore;
use Spora\Services\LocalAssetStore;

/**
 * The one place both producers read a parent asset's bytes, through the stores.
 */
final class WordSourceBytes
{
    public function __construct(
        private readonly DatabaseAssetStore $databaseAssetStore,
        private readonly LocalAssetStore $localAssetStore,
    ) {}

    /**
     * @param string $producer short class name, so a failure names the calling seam.
     *
     * @throws WordRuntimeException  when the row holds no materialised bytes.
     * @throws WordDocumentException when a local file is on disk but unreadable.
     */
    public function read(MediaAsset $asset, string $producer): string
    {
        return match ((string) $asset->storage_mode) {
            'data_url' => $this->readDatabaseBytes($asset, $producer),
            'local'    => $this->readLocalBytes($asset, $producer),
            default    => throw new WordRuntimeException(sprintf(
                '%s: asset %s is stored "%s", which holds no bytes to render. '
                    . 'A URL is not a body: nothing in the media tool fetches one for you, so the '
                    . 'document has to reach the archive as an upload or as text you pass to '
                    . 'media(action: "create_media").',
                $producer,
                (string) $asset->id,
                (string) $asset->storage_mode,
            )),
        };
    }

    private function readDatabaseBytes(MediaAsset $asset, string $producer): string
    {
        $stored = $this->mappedRead(
            fn(): array => $this->databaseAssetStore->read($asset),
            $asset,
            $producer,
        );
        if ($stored['bytes'] === '') {
            throw new WordRuntimeException(sprintf(
                '%s: asset %s has an empty data_url payload',
                $producer,
                (string) $asset->id,
            ));
        }

        return $stored['bytes'];
    }

    private function readLocalBytes(MediaAsset $asset, string $producer): string
    {
        $file = $this->mappedRead(
            fn(): array => $this->localAssetStore->readFromAsset($asset),
            $asset,
            $producer,
        );

        // Load-bearing: PHP 8.4+ no longer honours `@` here, so this handler is what
        // turns an unreadable file into a clean failure — and it must be paired, or a
        // second copy (see WordConversion) pops its frame.
        set_error_handler(static fn(): bool => true, E_WARNING);
        try {
            $bytes = file_get_contents($file['path']);
        } finally {
            restore_error_handler();
        }

        if (!is_string($bytes)) {
            throw new WordDocumentException(sprintf(
                '%s: asset %s local file is unreadable: %s',
                $producer,
                (string) $asset->id,
                $file['path'],
            ));
        }

        // No free emptiness check here as in `readDatabaseBytes()`.
        if ($bytes === '') {
            throw new WordRuntimeException(sprintf(
                '%s: asset %s has an empty local file',
                $producer,
                (string) $asset->id,
            ));
        }

        return $bytes;
    }

    /**
     * Both stores signal "no bytes" with {@see AssetStorageException}, not ours.
     *
     * @param  callable(): array{path?: string, length?: int, bytes?: string} $read
     * @return array{path?: string, length?: int, bytes?: string}
     */
    private function mappedRead(callable $read, MediaAsset $asset, string $producer): array
    {
        try {
            return $read();
        } catch (AssetStorageException $e) {
            throw new WordRuntimeException(sprintf(
                '%s: cannot read the bytes of asset %s: %s',
                $producer,
                (string) $asset->id,
                $e->getMessage(),
            ), previous: $e);
        }
    }
}
