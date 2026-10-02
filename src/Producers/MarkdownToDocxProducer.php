<?php

declare(strict_types=1);

namespace Spora\Plugins\Word\Producers;

use Spora\Models\MediaAsset;
use Spora\Plugins\Word\Exceptions\WordDocumentException;
use Spora\Plugins\Word\Exceptions\WordInvalidArgumentException;
use Spora\Plugins\Word\Exceptions\WordRuntimeException;
use Spora\Plugins\Word\Services\WordConversion;
use Spora\Services\AssetStorageException;
use Spora\Services\DatabaseAssetStore;
use Spora\Services\LocalAssetStore;
use Spora\Services\MediaArchive\DerivativeOutput;
use Spora\Services\MediaArchive\MediaDerivativeProducerInterface;

/**
 * Renders a Markdown asset into a Word document as a media derivative.
 *
 * This is the *binary re-render* half of the plugin: the output is an
 * artifact a user downloads, not text the LLM reads. The other half —
 * DOCX → Markdown — is {@see \Spora\Plugins\Word\Converters\DocxToMarkdownConverter},
 * because `MediaConverterInterface` is the only interface whose output
 * reaches `media_assets.markdown_content` and therefore the chat.
 *
 * The producer receives the already-resolved {@see MediaAsset} straight from
 * `MediaDerivativeService`, which has done the ownership check, so it reads
 * the payload off the row rather than going through `MediaAssetReader` —
 * that service is id-based and would repeat (and re-interpret) the check.
 *
 * Idempotency is delegated to `MediaDerivativeService::createFromRequest()`,
 * which keys on `(parent_id, format, producer_plugin, producer_operation)`.
 * That makes {@see self::pluginSlug()} and {@see self::operationName()} part
 * of the natural key: renaming either one orphans every derivative row
 * already written instead of refreshing them.
 */
final class MarkdownToDocxProducer implements MediaDerivativeProducerInterface
{
    /**
     * Source formats accepted. Deliberately tight: `media(create_media)`'s
     * default MIME hint is `text/markdown`, and the two extensions are here
     * so a row whose MIME sniff came back unhelpful can still be resolved by
     * its filename. Claiming `text/plain` as well would make every text asset
     * in the archive convertible, which is a different decision.
     *
     * @var list<string>
     */
    private const SUPPORTED_SOURCE_FORMATS = ['text/markdown', 'md', 'markdown'];

    /**
     * `docx` is the only derivative format. It is four characters against the
     * 16-character `media_derivatives.format` column, so the identifier
     * cannot be renamed later without a migration.
     *
     * @var list<string>
     */
    private const SUPPORTED_FORMATS = ['docx'];

    public function __construct(
        private readonly WordConversion $conversion,
        private readonly DatabaseAssetStore $databaseAssetStore,
        private readonly LocalAssetStore $localAssetStore,
    ) {}

    /**
     * The Composer **package** name, matching what
     * `spora-plugin-typst`'s producer returns rather than the `plugin.json`
     * slug. Persisted into `media_derivatives.producer_plugin` and part of the
     * idempotency natural key — changing it orphans existing rows.
     */
    public function pluginSlug(): string
    {
        return 'spora-plugin-word';
    }

    public function operationName(): string
    {
        return 'word.render';
    }

    /**
     * @return list<string>
     */
    public function supportedSourceFormats(): array
    {
        return self::SUPPORTED_SOURCE_FORMATS;
    }

    /**
     * @return list<string>
     */
    public function supportedDerivativeFormats(): array
    {
        return self::SUPPORTED_FORMATS;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @throws WordInvalidArgumentException when `$format` is not `docx`.
     * @throws WordDocumentException        when the source is oversized or unreadable.
     * @throws WordRuntimeException         when the asset holds no materialised bytes.
     */
    public function produce(MediaAsset $source, string $format, array $options = []): DerivativeOutput
    {
        $format = strtolower(trim($format));
        if (!in_array($format, self::SUPPORTED_FORMATS, true)) {
            throw new WordInvalidArgumentException(sprintf(
                'MarkdownToDocxProducer: unsupported derivative format "%s" (supported: %s)',
                $format,
                implode(', ', self::SUPPORTED_FORMATS),
            ));
        }

        $docx = $this->conversion->markdownToDocx(
            $this->loadSourceBytes($source),
            // `withoutDecoration()` — the one knob v1 puts in the LLM's
            // hands. See WordConversion::markdownToDocx().
            plain: ($options['plain'] ?? false) === true,
        );

        return new DerivativeOutput(
            bytes: $docx,
            mime: WordConversion::DOCX_MIME,
            width: null,
            height: null,
            durationSeconds: null,
        );
    }

    private function loadSourceBytes(MediaAsset $asset): string
    {
        return match ((string) $asset->storage_mode) {
            'data_url' => $this->readDatabaseBytes($asset),
            'local'    => $this->readLocalBytes($asset),
            default    => throw new WordRuntimeException(sprintf(
                'MarkdownToDocxProducer: asset %s is stored "%s", which holds no bytes to render. '
                    . 'A URL is not a body: nothing in the media tool fetches one for you, so the '
                    . 'document has to reach the archive as an upload or as text you pass to '
                    . 'media(action: "create_media").',
                (string) $asset->id,
                (string) $asset->storage_mode,
            )),
        };
    }

    private function readDatabaseBytes(MediaAsset $asset): string
    {
        // Routed through DatabaseAssetStore rather than reading `$asset->payload`
        // directly so both storage modes share one emptiness check and one
        // failure message.
        $stored = $this->mappedRead(
            fn(): array => $this->databaseAssetStore->read($asset),
            $asset,
        );
        if ($stored['bytes'] === '') {
            throw new WordRuntimeException(sprintf(
                'MarkdownToDocxProducer: asset %s has an empty data_url payload',
                (string) $asset->id,
            ));
        }

        return $stored['bytes'];
    }

    private function readLocalBytes(MediaAsset $asset): string
    {
        $file = $this->mappedRead(
            fn(): array => $this->localAssetStore->readFromAsset($asset),
            $asset,
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
                'MarkdownToDocxProducer: asset %s local file is unreadable: %s',
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
                'MarkdownToDocxProducer: asset %s has an empty local file',
                (string) $asset->id,
            ));
        }

        return $bytes;
    }

    /**
     * Run a core asset-store read and translate its
     * {@see AssetStorageException} into the plugin's own hierarchy.
     *
     * Both stores signal "this asset has no bytes you can read" with that
     * class — a legacy data_url row with a null payload, a missing
     * `asset_token`, a file deleted out from under the row. Left alone it
     * escapes as a `RuntimeException` from `Spora\Services`, which breaks
     * the promise that callers only ever see `WordRuntimeException`.
     *
     * @param callable(): array{path?: string, length?: int, bytes?: string} $read
     *
     * @return array{path?: string, length?: int, bytes?: string}
     */
    private function mappedRead(callable $read, MediaAsset $asset): array
    {
        try {
            return $read();
        } catch (AssetStorageException $e) {
            throw new WordRuntimeException(sprintf(
                'MarkdownToDocxProducer: cannot read the bytes of asset %s: %s',
                (string) $asset->id,
                $e->getMessage(),
            ), previous: $e);
        }
    }
}
