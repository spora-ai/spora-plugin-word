<?php

declare(strict_types=1);

namespace Spora\Plugins\Office\Producers;

use Spora\Models\MediaAsset;
use Spora\Plugins\Office\Exceptions\OfficeInvalidArgumentException;
use Spora\Plugins\Office\Services\OfficeConversion;
use Spora\Plugins\Office\Services\OfficeSourceBytes;
use Spora\Services\MediaArchive\DerivativeOutput;
use Spora\Services\MediaArchive\MediaDerivativeProducerInterface;

/**
 * The read direction: an `md` derivative {@see MarkdownToOfficeProducer} accepts
 * back.
 *
 * `supportedSourceFormats()` is load-bearing beyond routing — core unions every
 * registered producer's source formats into the upload allowlist and this is the
 * plugin's only contribution there, so dropping the DOCX MIME makes every `.docx`
 * upload a 415. The bare `docx` entry only rescues a coarse `application/zip` sniff;
 * that container type is never declared.
 *
 * That union arrives with core's md-derivative cut, not before — merged early,
 * the plugin boots and every `.docx` upload 415s. Full note in README.md.
 *
 * `MediaDerivativeService` keys on `(parent_id, format, producer_plugin,
 * producer_operation)`, so the operation name is part of the natural key.
 */
final class OfficeToMarkdownProducer implements MediaDerivativeProducerInterface
{
    /** The MIME is the allowlist entry; the extension is the filename fallback. */
    private const SUPPORTED_SOURCE_FORMATS = [OfficeConversion::DOCX_MIME, 'docx'];

    private const SUPPORTED_FORMATS = ['md'];

    public function __construct(
        private readonly OfficeConversion $conversion,
        private readonly OfficeSourceBytes $sourceBytes,
    ) {}

    public function pluginSlug(): string
    {
        return 'spora-plugin-office';
    }

    public function operationName(): string
    {
        return 'office.extract';
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
     * @param array<string, mixed> $options unused: this direction is lossless.
     *
     * @throws OfficeInvalidArgumentException when `$format` is not `md`.
     * @throws \Spora\Plugins\Office\Exceptions\OfficeDocumentException on bad bytes.
     */
    public function produce(MediaAsset $source, string $format, array $options = []): DerivativeOutput
    {
        $format = strtolower(trim($format));

        if (!in_array($format, self::SUPPORTED_FORMATS, true)) {
            throw new OfficeInvalidArgumentException(sprintf(
                'OfficeToMarkdownProducer: unsupported derivative format "%s" (supported: %s)',
                $format,
                implode(', ', self::SUPPORTED_FORMATS),
            ));
        }

        // Decides from the bytes: neither the row MIME nor its filename is passed on.
        return new DerivativeOutput(
            bytes: $this->conversion->officeToMarkdown(
                $this->sourceBytes->read($source, 'OfficeToMarkdownProducer'),
            ),
            mime: OfficeConversion::MARKDOWN_MIME,
            width: null,
            height: null,
            durationSeconds: null,
        );
    }
}
