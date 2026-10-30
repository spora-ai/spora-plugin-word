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
 * Markdown into a PowerPoint deck.
 *
 * A deck is not a document: it is split into slides by `---` and each slide
 * carries its own frontmatter, so the Markdown an agent writes for a deck is
 * not the Markdown it writes for a report. The skill says so.
 */
final class MarkdownToPresentationProducer implements MediaDerivativeProducerInterface
{
    /**
     * @var list<string>
     */
    private const SUPPORTED_SOURCE_FORMATS = ['text/markdown', 'md', 'markdown'];

    /**
     * @var list<string>
     */
    private const SUPPORTED_FORMATS = ['pptx'];

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
        return 'presentation.render';
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
     * @throws OfficeInvalidArgumentException when `$format` is not `pptx`.
     * @throws OfficeDocumentException        when the source is oversized or unreadable.
     * @throws OfficeRuntimeException         when the asset holds no materialised bytes.
     */
    public function produce(MediaAsset $source, string $format, array $options = []): DerivativeOutput
    {
        $format = strtolower(trim($format));

        if (!in_array($format, self::SUPPORTED_FORMATS, true)) {
            throw new OfficeInvalidArgumentException(sprintf(
                'MarkdownToPresentationProducer: unsupported derivative format "%s" (supported: %s)',
                $format,
                implode(', ', self::SUPPORTED_FORMATS),
            ));
        }

        $markdown = $this->sourceBytes->read($source, 'MarkdownToPresentationProducer');

        $principalId = $source->principal_id === null ? null : (int) $source->principal_id;

        $theme = $options['theme'] ?? null;

        return new DerivativeOutput(
            bytes: $this->conversion->markdownToPresentation(
                $markdown,
                $principalId,
                is_string($theme) && trim($theme) !== '' ? trim($theme) : null,
            ),
            mime: OfficeConversion::PPTX_MIME,
            width: null,
            height: null,
            durationSeconds: null,
        );
    }
}
