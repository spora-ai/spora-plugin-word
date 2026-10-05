<?php

declare(strict_types=1);

namespace Spora\Plugins\Word\Producers;

use Spora\Models\MediaAsset;
use Spora\Plugins\Word\Exceptions\WordDocumentException;
use Spora\Plugins\Word\Exceptions\WordInvalidArgumentException;
use Spora\Plugins\Word\Exceptions\WordRuntimeException;
use Spora\Plugins\Word\Services\WordConversion;
use Spora\Plugins\Word\Services\WordSourceBytes;
use Spora\Services\MediaArchive\DerivativeOutput;
use Spora\Services\MediaArchive\MediaDerivativeProducerInterface;

/**
 * Renders a Markdown asset into a Word document as a media derivative.
 *
 * Reads through {@see WordSourceBytes}, not the id-based `MediaAssetReader`.
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
        private readonly WordSourceBytes $sourceBytes,
    ) {}

    /**
     * The Composer **package** name, matching what
     * `spora-plugin-typst`'s producer returns rather than the `plugin.json`
     * slug.
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
            $this->sourceBytes->read($source, 'MarkdownToDocxProducer'),
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
}
