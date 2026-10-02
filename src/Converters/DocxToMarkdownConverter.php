<?php

declare(strict_types=1);

namespace Spora\Plugins\Word\Converters;

use Spora\Plugins\Word\Services\WordConversion;
use Spora\Services\MediaArchive\MediaConverterInterface;

/**
 * Turns a Word document into GitHub-Flavored Markdown at ingest time.
 *
 * This is the *chat-input* half of the plugin. `MediaConverterInterface` is
 * the only media extension point whose output reaches
 * `media_assets.markdown_content`, which is what
 * `MessageHistoryBuilder::buildTextBlock()` inlines into the model's
 * context — a derivative's bytes reach the LLM as a `ToolResult` and
 * nothing else. Registering this converter is also what adds the DOCX MIME
 * to `MediaAllowedTypesService`'s upload allowlist, so a `.docx` an operator
 * drops into chat arrives with its text already extracted.
 *
 * `application/zip` is deliberately **not** declared. Every registered
 * converter's MIMEs are unioned into the upload allowlist, so claiming the
 * container type would push every zip in the world through the picker.
 * {@see \Spora\Plugins\Word\Refiners\WordDocxMimeRefiner} fixes the
 * mis-sniffed DOCX upstream of the allowlist check instead, so the document
 * is correctly typed before the gate ever runs.
 *
 * Failures propagate. `MediaArchiveIngestPipeline::runConversionPipeline()`
 * catches `Throwable`, logs at `warning` and leaves `markdown_content` NULL,
 * which is exactly the degradation wanted: a hostile or corrupt `.docx`
 * cannot fail an upload, and the chat falls back to the metadata block.
 */
final class DocxToMarkdownConverter implements MediaConverterInterface
{
    public function __construct(private readonly WordConversion $conversion) {}

    /**
     * @return list<string>
     */
    public function supportedMimeTypes(): array
    {
        return [WordConversion::DOCX_MIME];
    }

    /**
     * @return list<string>
     */
    public function supportedExtensions(): array
    {
        return ['docx'];
    }

    /**
     * @throws \Spora\Plugins\Word\Exceptions\WordDocumentException when the bytes
     *         are not a Word document this can read.
     */
    public function toMarkdown(string $bytes, string $mime, ?string $filename = null): string
    {
        // `$mime` and `$filename` are deliberately unread: the registry only
        // routes here for something it already believes is a DOCX, and the
        // reader decides from the bytes. A `.docx` extension is never enough
        // evidence — legacy OLE `.doc` is a different format entirely and
        // fails loudly rather than silently.
        return $this->conversion->docxToMarkdown($bytes);
    }
}
