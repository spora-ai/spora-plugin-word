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
 * Extracts a Word document into GitHub-Flavored Markdown as an `md`
 * derivative.
 *
 * The *chat-input* half of the plugin, and the same fact in the opposite
 * direction to {@see MarkdownToDocxProducer}: a `.docx` becomes a Markdown
 * derivative, and that derivative is itself a legal parent for a `.docx` one.
 * Both live on {@see MediaDerivativeProducerInterface} rather than splitting
 * a `bytes → string` contract off against a `MediaAsset` → row contract, so
 * one seam carries both and the round trip stays inside one interface.
 *
 * The producer is handed an already-resolved {@see MediaAsset} from
 * `MediaDerivativeService`, which has done the ownership check — so it reads
 * the payload through {@see WordSourceBytes} rather than
 * `MediaAssetReader`, which is id-based and would repeat the check.
 *
 * **The DOCX MIME declared below is the load-bearing part.** Every registered
 * producer's `supportedSourceFormats()` is unioned into
 * `MediaAllowedTypesService`'s upload allowlist, and `.docx` reaches that list
 * *only* through this method — so dropping the MIME here does not degrade the
 * extraction, it makes every `.docx` upload a 415 at the gate. The bare `docx`
 * entry is the second line of defence behind {@see \Spora\Plugins\Word\Refiners\WordDocxMimeRefiner}:
 * when the bytes sniffed as a coarse `application/zip`, a filename ending
 * `.docx` still resolves the parent to this producer. Both the MIME and the
 * extension are needed, and only the MIME is an allowlist entry.
 *
 * **That union arrives with core's md-derivative cut, not before.**
 * `allowedMimeTypes()` matches by exact string, so no `text/*` prefix rule
 * stands in for the entry, and on a core that predates the cut the same
 * allowlist entry is fed by the *converter* registry — which that cut deletes
 * and which this PR stops writing to. Merged early, the plugin still boots
 * and every `.docx` upload is a 415 at the gate.
 *
 * `application/zip` is deliberately **not** declared — claiming the container
 * type would push every zip in the world through the upload picker.
 *
 * Failures propagate. `produce()`'s {@see \Spora\Plugins\Word\Exceptions\WordDocumentException}
 * reaches the caller verbatim, which is what lets a hostile or corrupt `.docx`
 * degrade the way an upload should — the caller logs and moves on rather than
 * failing — while an explicit `create_derivative` still reports the reason.
 *
 * Idempotency is delegated to `MediaDerivativeService`, which keys on
 * `(parent_id, format, producer_plugin, producer_operation)`. That makes
 * {@see self::pluginSlug()} and {@see self::operationName()} part of the
 * natural key: renaming either one orphans every derivative row already written
 * instead of refreshing them. `word.extract` is distinct from the render
 * producer's `word.render` for exactly that reason — a shared name would
 * collapse the two directions onto one row.
 */
final class DocxToMarkdownProducer implements MediaDerivativeProducerInterface
{
    /**
     * Source formats accepted: the DOCX MIME and the bare extension, so a
     * parent resolves on either the sniffed type or the filename.
     *
     * @var list<string>
     */
    private const SUPPORTED_SOURCE_FORMATS = [WordConversion::DOCX_MIME, 'docx'];

    /**
     * `md` is the only derivative format. It is two characters against the
     * 16-character `media_derivatives.format` column, so the identifier
     * cannot be renamed later without a migration.
     *
     * @var list<string>
     */
    private const SUPPORTED_FORMATS = ['md'];

    /**
     * The MIME the derivative row is stamped with, and the one
     * {@see MarkdownToDocxProducer} accepts as a source — which is what makes
     * the `md` output a legal parent for a `.docx` render, with no format
     * juggling in between.
     * {@see \Spora\Services\MediaArchive\MediaType::fromMime()} reads it as
     * `Document`, which is what the archive and the chat file card want for
     * text a user might download.
     */
    private const MARKDOWN_MIME = 'text/markdown';

    public function __construct(
        private readonly WordConversion $conversion,
        private readonly WordSourceBytes $sourceBytes,
    ) {}

    /**
     * The Composer **package** name, matching what
     * {@see MarkdownToDocxProducer::pluginSlug()} returns.
     */
    public function pluginSlug(): string
    {
        return 'spora-plugin-word';
    }

    public function operationName(): string
    {
        return 'word.extract';
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
     * @param array<string, mixed> $options unused. The direction is lossless
     *        by construction — there is no knob that changes what a document
     *        says — so a caller passing anything is better served by the
     *        output than by a silent reinterpretation.
     *
     * @throws WordInvalidArgumentException when `$format` is not `md`.
     * @throws \Spora\Plugins\Word\Exceptions\WordDocumentException when the
     *         source is not a readable Word document.
     * @throws \Spora\Plugins\Word\Exceptions\WordRuntimeException when the
     *         asset holds no materialised bytes.
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

        // The reader decides from the bytes, never from the row's MIME or
        // filename: a legacy OLE `.doc` is a different format entirely and has
        // to fail loudly rather than silently, and a `.docx` extension is
        // never enough evidence. Neither hint is even passed on.
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
