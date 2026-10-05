<?php

declare(strict_types=1);

namespace Spora\Plugins\Word\Producers;

use Spora\Models\MediaAsset;
use Spora\Plugins\Word\Exceptions\WordInvalidArgumentException;
use Spora\Plugins\Word\Services\WordConversion;
use Spora\Plugins\Word\Services\WordSourceBytes;
use Spora\Services\MediaArchive\DerivativeOutput;
use Spora\Services\MediaArchive\MediaDerivativeProducerInterface;

/**
 * The read direction: an `md` derivative {@see MarkdownToDocxProducer} accepts back.
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
 * producer_operation)`, so one shared operation name collapses both onto one row.
 */
final class DocxToMarkdownProducer implements MediaDerivativeProducerInterface
{
    /** The MIME is the allowlist entry; the extension is the filename fallback. */
    private const SUPPORTED_SOURCE_FORMATS = [WordConversion::DOCX_MIME, 'docx'];

    private const SUPPORTED_FORMATS = ['md'];

    private const MARKDOWN_MIME = 'text/markdown';

    public function __construct(
        private readonly WordConversion $conversion,
        private readonly WordSourceBytes $sourceBytes,
    ) {}

    public function pluginSlug(): string
    {
        return 'spora-plugin-word';
    }

    public function operationName(): string
    {
        return 'word.extract';
    }

    public function supportedSourceFormats(): array
    {
        return self::SUPPORTED_SOURCE_FORMATS;
    }

    public function supportedDerivativeFormats(): array
    {
        return self::SUPPORTED_FORMATS;
    }

    /**
     * @param array<string, mixed> $options unused: this direction is lossless.
     *
     * @throws WordInvalidArgumentException when `$format` is not `md`.
     * @throws \Spora\Plugins\Word\Exceptions\WordDocumentException on bad bytes.
     */
    public function produce(MediaAsset $source, string $format, array $options = []): DerivativeOutput
    {
        $format = strtolower(trim($format));
        if (!in_array($format, self::SUPPORTED_FORMATS, true)) {
            throw new WordInvalidArgumentException(sprintf(
                'DocxToMarkdownProducer: unsupported derivative format "%s" (supported: %s)',
                $format,
                implode(', ', self::SUPPORTED_FORMATS),
            ));
        }

        // Decides from the bytes: neither the row MIME nor its filename is passed on.
        return new DerivativeOutput(
            bytes: $this->conversion->docxToMarkdown(
                $this->sourceBytes->read($source, 'DocxToMarkdownProducer'),
            ),
            mime: self::MARKDOWN_MIME,
            width: null,
            height: null,
            durationSeconds: null,
        );
    }
}
