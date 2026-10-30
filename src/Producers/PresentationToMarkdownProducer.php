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
 * The read direction for decks: a `.pptx` back as Markdown.
 *
 * The result is a deck Markdown — the format the render producer reads — so a
 * deck that came out of the archive can be edited as text and rendered again
 * in another design.
 *
 * The PPTX MIME is the plugin's contribution to the upload allowlist, the same
 * way the DOCX MIME is {@see OfficeToMarkdownProducer}'s.
 */
final class PresentationToMarkdownProducer implements MediaDerivativeProducerInterface
{
    /** The MIME is the allowlist entry; the extension is the filename fallback. */
    private const SUPPORTED_SOURCE_FORMATS = [OfficeConversion::PPTX_MIME, 'pptx'];

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
        return 'presentation.extract';
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
                'PresentationToMarkdownProducer: unsupported derivative format "%s" (supported: %s)',
                $format,
                implode(', ', self::SUPPORTED_FORMATS),
            ));
        }

        return new DerivativeOutput(
            bytes: $this->conversion->presentationToMarkdown(
                $this->sourceBytes->read($source, 'PresentationToMarkdownProducer'),
            ),
            mime: OfficeConversion::MARKDOWN_MIME,
            width: null,
            height: null,
            durationSeconds: null,
        );
    }
}
