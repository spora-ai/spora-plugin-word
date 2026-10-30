<?php

declare(strict_types=1);

namespace Spora\Plugins\Office\Producers;

use Spora\Models\MediaAsset;
use Spora\Plugins\Office\Exceptions\OfficeDocumentException;
use Spora\Plugins\Office\Exceptions\OfficeInvalidArgumentException;
use Spora\Plugins\Office\Exceptions\OfficeRuntimeException;
use Spora\Plugins\Office\Services\OfficeConversion;
use Spora\Plugins\Office\Services\OfficeSourceBytes;
use Spora\Services\MediaArchive\DerivativeOutput;
use Spora\Services\MediaArchive\MediaDerivativeProducerInterface;

/**
 * Reads through {@see OfficeSourceBytes}, not the id-based `MediaAssetReader`.
 *
 * Idempotency is delegated to `MediaDerivativeService::createFromRequest()`,
 * which keys on `(parent_id, format, producer_plugin, producer_operation)`.
 * That makes {@see self::pluginSlug()} and {@see self::operationName()} part
 * of the natural key: renaming either one orphans every derivative row
 * already written instead of refreshing them.
 */
final class MarkdownToOfficeProducer implements MediaDerivativeProducerInterface
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
     * The formats this producer emits. Each is four characters against the
     * 16-character `media_derivatives.format` column, so none of them can be
     * renamed later without a migration.
     *
     * @var list<string>
     */
    private const SUPPORTED_FORMATS = ['docx', 'odt', 'rtf'];

    /** @var array<string, string> */
    private const MIME_BY_FORMAT = [
        'docx' => OfficeConversion::DOCX_MIME,
        'odt'  => OfficeConversion::ODT_MIME,
        'rtf'  => OfficeConversion::RTF_MIME,
    ];

    public function __construct(
        private readonly OfficeConversion $conversion,
        private readonly OfficeSourceBytes $sourceBytes,
    ) {}

    /**
     * The unscoped Composer package name — what `spora-plugin-typst`'s producer
     * returns, not the `plugin.json` slug (`office`).
     */
    public function pluginSlug(): string
    {
        return 'spora-plugin-office';
    }

    public function operationName(): string
    {
        return 'office.render';
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
     * @throws OfficeInvalidArgumentException when `$format` is not one this producer emits.
     * @throws OfficeDocumentException        when the source is oversized or unreadable.
     * @throws OfficeRuntimeException         when the asset holds no materialised bytes.
     */
    public function produce(MediaAsset $source, string $format, array $options = []): DerivativeOutput
    {
        $format = strtolower(trim($format));

        if (!isset(self::MIME_BY_FORMAT[$format])) {
            throw new OfficeInvalidArgumentException(sprintf(
                'MarkdownToOfficeProducer: unsupported derivative format "%s" (supported: %s)',
                $format,
                implode(', ', self::SUPPORTED_FORMATS),
            ));
        }

        $markdown = $this->sourceBytes->read($source, 'MarkdownToOfficeProducer');

        $principalId = $source->principal_id === null ? null : (int) $source->principal_id;
        $theme       = self::theme($options);
        $plain       = ($options['plain'] ?? false) === true;

        $bytes = match ($format) {
            'docx' => $this->conversion->markdownToDocx($markdown, $principalId, $theme, $plain),
            'odt'  => $this->conversion->markdownToOdt($markdown, $principalId, $theme, $plain),
            'rtf'  => $this->conversion->markdownToRtf($markdown, $principalId, $theme, $plain),
        };

        return new DerivativeOutput(
            bytes: $bytes,
            mime: self::MIME_BY_FORMAT[$format],
            width: null,
            height: null,
            durationSeconds: null,
        );
    }

    /**
     * The theme the caller named, when it named one.
     *
     * A theme named here answers for the document, so the frontmatter's
     * `theme:` is not read. The option is a string and nothing else: a
     * non-string is a document asking for something that is not a theme, and
     * the library's own refusal is the better sentence.
     */
    private static function theme(array $options): ?string
    {
        $theme = $options['theme'] ?? null;

        return is_string($theme) && trim($theme) !== '' ? trim($theme) : null;
    }
}
