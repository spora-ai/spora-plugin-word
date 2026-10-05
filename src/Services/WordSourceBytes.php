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
 * The single place both producers read a parent asset's bytes.
 *
 * A sibling of {@see WordConversion} for the same reason: the scoped
 * `set_error_handler` around the disk read must not exist in two copies that
 * could interleave and pop each other's frame, and both storage modes have to
 * produce one emptiness check and one failure message.
 *
 * Reads go through the archive's own asset stores rather than off
 * `$asset->payload` directly, so `data_url` and `local` resolve the same way
 * {@see \Spora\Services\MediaArchive\Producers\ImageDerivativeProducer} does.
 */
final class WordSourceBytes
{
    public function __construct(
        private readonly DatabaseAssetStore $databaseAssetStore,
        private readonly LocalAssetStore $localAssetStore,
    ) {}

    /**
     * @param string $producer short class name of the caller, so a failure
     *        names the seam it reached through rather than this helper.
     *
     * @throws WordRuntimeException     when the row holds no materialised
     *         bytes, or when the archive's store cannot produce them.
     * @throws WordDocumentException    when a local file is on disk but
     *         unreadable.
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

        // PHP 8.4+ no longer honours `@` for `file_get_contents`, so the
        // explicit handler is what turns an unreadable file into a clean
        // failure. Same pattern as ImageDerivativeProducer::readLocalBytes().
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

        if ($bytes === '') {
            // The emptiness check `readDatabaseBytes()` gets for free from
            // comparing against `''` has to be spelled out here: without it
            // a zero-byte local file renders as an empty document instead
            // of raising the same failure as its data_url twin.
            throw new WordRuntimeException(sprintf(
                '%s: asset %s has an empty local file',
                $producer,
                (string) $asset->id,
            ));
        }

        return $bytes;
    }

    /**
     * Both stores signal "this asset has no bytes you can read" with
     * {@see AssetStorageException} — a legacy data_url row with a null
     * payload, a missing `asset_token`, a file deleted out from under the row.
     * Left alone it escapes as a `RuntimeException` from `Spora\Services`,
     * which breaks the promise that callers only ever see
     * `WordRuntimeException`.
     *
     * @param callable(): array{path?: string, length?: int, bytes?: string} $read
     *
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
