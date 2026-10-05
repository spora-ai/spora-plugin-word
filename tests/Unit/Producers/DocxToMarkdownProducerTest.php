<?php

declare(strict_types=1);

use MarkdownWord\Exception\UnreadableDocument;
use Spora\Plugins\Word\Exceptions\WordDocumentException;
use Spora\Plugins\Word\Exceptions\WordInvalidArgumentException;
use Spora\Plugins\Word\Exceptions\WordRuntimeException;
use Spora\Plugins\Word\Services\WordConversion;
use Spora\Plugins\Word\Tests\Support\DeprecationFilter;
use Spora\Plugins\Word\Tests\Support\DocxFixtures;
use Spora\Plugins\Word\Tests\Support\ErrorHandlerStack;
use Spora\Plugins\Word\Tests\Support\ErrorRecorder;
use Spora\Plugins\Word\Tests\Support\WordMediaArchive;
use Spora\Services\MediaArchive\MediaArchiveService;

beforeEach(function () {
    DeprecationFilter::silencePhpWord();
});

afterEach(function () {
    DeprecationFilter::restore();
});

/**
 * Every value here is persisted or matched against. `pluginSlug()` and
 * `operationName()` land in `media_derivatives` and are half that table's
 * natural key, so renaming either orphans every derivative already written
 * instead of refreshing it. The source list is what
 * `MediaDerivativeService::findProducer()` matches a parent's MIME and
 * extension against, and the format list is the 16-character
 * `media_derivatives.format` column.
 */
it('declares the identity the media_derivatives natural key is built from', function () {
    $producer = WordMediaArchive::extractor(WordMediaArchive::container());

    expect($producer->pluginSlug())->toBe('spora-plugin-word')
        // Distinct from the render producer's `word.render`, and it has to
        // be: the natural key would otherwise collapse the two directions
        // onto one row.
        ->and($producer->operationName())->toBe('word.extract')
        ->and($producer->supportedDerivativeFormats())->toBe(['md'])
        ->and($producer->supportedSourceFormats())->toBe([WordConversion::DOCX_MIME, 'docx'])
        ->and(strlen((string) $producer->supportedDerivativeFormats()[0]))->toBeLessThanOrEqual(16);
});

/**
 * `MediaAllowedTypesService` publishes the union of every registered
 * producer's source formats as the upload allowlist, filtered on the entries
 * containing a `/`. One stray `application/zip` here would put every zip
 * archive in the world into the chat picker's `accept` attribute, and — the
 * load-bearing half — dropping the DOCX MIME from the same list would make
 * every `.docx` upload a 415 at the gate. Both directions of that trade are
 * asserted over the union an operator would actually ship, not over this
 * producer alone.
 */
it('keeps the docx mime in the upload allowlist without widening it to the zip container', function () {
    $container = WordMediaArchive::container();
    $extract   = WordMediaArchive::extractor($container);
    $render    = WordMediaArchive::producer($container);

    // What core's `/` filter leaves in the LLM-facing "Allowed: %s" string.
    // The bare extensions each producer also declares are dropped by that
    // filter, which is why they are harmless here.
    $allowlist = [];
    foreach ([$extract, $render] as $producer) {
        foreach ($producer->supportedSourceFormats() as $format) {
            if (str_contains($format, '/')) {
                $allowlist[] = $format;
            }
        }
    }

    expect($allowlist)->toContain(WordConversion::DOCX_MIME)
        ->and($allowlist)->toContain('text/markdown')
        ->and($allowlist)->not->toContain(DocxFixtures::ZIP_MIME);
});

it('round-trips a real document back into github-flavoured markdown', function () {
    $producer = WordMediaArchive::extractor(WordMediaArchive::container());

    $output = $producer->produce(WordMediaArchive::docxAsset(DocxFixtures::docx()), 'md');

    // Structure, not just "non-empty": an extractor that returned a single
    // line of prose would satisfy a length check and hand the model
    // something with no headings, lists or tables to reason about.
    expect($output->bytes)->toContain('# Quarterly report')
        ->and($output->bytes)->toContain('Revenue grew **12%** against a flat market.')
        ->and($output->bytes)->toContain('- North grew')
        ->and($output->bytes)->toContain('- South held')
        // The round trip's documented header-row rewrite: the alignment
        // markers survive, the cell emphasis does not.
        ->and($output->bytes)->toContain('| **Region** | **Total** |')
        ->and($output->bytes)->toContain('| :-- | --: |')
        ->and($output->bytes)->toContain('| North | 1,200 |');
});

/**
 * The extract's output is the render's input, and that is the whole reason
 * both directions live on one contract: nothing between them has to rewrite
 * the format or re-type the MIME for the round trip to close.
 */
it('emits the exact source format the render producer accepts', function () {
    $extract = WordMediaArchive::extractor(WordMediaArchive::container());
    $render  = WordMediaArchive::producer(WordMediaArchive::container());

    $output = $extract->produce(WordMediaArchive::docxAsset(DocxFixtures::docx()), 'md');

    // `MediaDerivativeService::findProducer()` matches the parent's MIME
    // first and its extension second, so either entry is enough — but only
    // if the emitted MIME is one the render actually declared.
    expect($render->supportedSourceFormats())->toContain($output->mime)
        // the 16-character format column and the `.md` filename core
        // derives from the MIME have to agree too, or the derivative is
        // stored under a name the resolver cannot route back.
        ->and(MediaArchiveService::extensionForMime($output->mime))
        ->toBe($extract->supportedDerivativeFormats()[0]);
});

it('rejects a format it cannot emit before it touches the asset', function () {
    $producer = WordMediaArchive::extractor(WordMediaArchive::container());
    $caught   = null;

    // `external` has no readable bytes, so reaching `WordRuntimeException`
    // instead of `WordInvalidArgumentException` would mean the format gate
    // ran too late. The order is the contract: an LLM asking for `docx`
    // must be told the format is wrong, not that its document is missing.
    try {
        $producer->produce(WordMediaArchive::externalAsset('report.docx'), 'docx');
    } catch (WordInvalidArgumentException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(WordInvalidArgumentException::class)
        // The supported list travels with the failure so the LLM can retry
        // without a second round trip.
        ->and($caught->getMessage())->toContain('docx')
        ->and($caught->getMessage())->toContain('md');
});

it('tolerates a padded or mixed-case format identifier', function () {
    $producer = WordMediaArchive::extractor(WordMediaArchive::container());

    $output = $producer->produce(WordMediaArchive::docxAsset(DocxFixtures::docx()), ' MD ');

    expect($output->mime)->toBe('text/markdown')
        ->and($output->bytes)->toContain('# Quarterly report');
});

it('reads a local-mode document back off disk', function () {
    $container = WordMediaArchive::container();
    $producer  = WordMediaArchive::extractor($container);

    // The second of the producer's two storage branches. It installs its
    // own `set_error_handler` around the `file_get_contents`, which is why
    // this mode is exercised at all rather than only reasoned about.
    $docx  = DocxFixtures::docx();
    $local = WordMediaArchive::localDocxAsset($container, $docx);
    $output = $producer->produce($local['asset'], 'md');

    expect($output->bytes)->toContain('# Quarterly report')
        // The row really is disk-backed: the store the producer reads
        // through resolves it to a file the fixture wrote, byte for byte.
        ->and(file_get_contents($local['store']->readFromAsset($local['asset'])['path']))
        ->toBe($docx);
});

it('names a remedy that exists for an externally stored document', function () {
    $producer = WordMediaArchive::extractor(WordMediaArchive::container());
    $caught   = null;

    try {
        $producer->produce(WordMediaArchive::externalAsset('report.docx'), 'md');
    } catch (WordRuntimeException $e) {
        $caught = $e;
    }

    // The remedy has to be a call the model can actually make, and for a
    // `.docx` parent `create_media` is not one — the document has to reach
    // the archive as an upload, which is what the message points at.
    expect($caught)->toBeInstanceOf(WordRuntimeException::class)
        ->and($caught->getMessage())->toContain('external')
        ->and($caught->getMessage())->toContain('create_media');
});

it('decides from the bytes, not from the row it was handed', function () {
    $producer = WordMediaArchive::extractor(WordMediaArchive::container());

    // A legacy OLE `.doc` is a different format entirely. A row whose MIME
    // says DOCX and whose filename ends `.docx` must not make the producer
    // attempt a read it cannot do.
    $ole    = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat("\x00", 512);
    $caught = null;

    try {
        $producer->produce(WordMediaArchive::docxAsset($ole, 'legacy.docx'), 'md');
    } catch (WordDocumentException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(WordDocumentException::class)
        // The upstream exception survives as `$previous`: the caller logs
        // `$e->getMessage()`, and that line should still carry the library's
        // own wording.
        ->and($caught->getPrevious())->toBeInstanceOf(UnreadableDocument::class)
        ->and($caught->getMessage())->toContain('Reading the Word document');

    // The mirror image: a real document is unaffected by a wrong row, since
    // the reader never sees the MIME or the filename.
    $lying = WordMediaArchive::docxAsset(DocxFixtures::docx(), 'report.bin');
    $lying->mime_type = 'application/octet-stream';

    expect($producer->produce($lying, 'md')->bytes)->toContain('# Quarterly report');
});

it('refuses bytes that are not an archive at all', function () {
    $producer = WordMediaArchive::extractor(WordMediaArchive::container());
    $caught   = null;

    try {
        $producer->produce(WordMediaArchive::docxAsset('this is not a zip archive', 'notes.docx'), 'md');
    } catch (WordDocumentException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(WordDocumentException::class)
        ->and($caught->getPrevious())->toBeInstanceOf(UnreadableDocument::class);
});

it('refuses a document that was cut short after its first entries', function () {
    $producer = WordMediaArchive::extractor(WordMediaArchive::container());
    $truncated = DocxFixtures::truncatedDocx();
    $caught   = null;

    try {
        $producer->produce(WordMediaArchive::docxAsset($truncated, 'broken.docx'), 'md');
    } catch (WordDocumentException $e) {
        $caught = $e;
    }

    // `finfo` still names a truncated file a Word document from its local
    // file header, so it really does reach this producer — the failure has
    // to surface here as a `WordDocumentException` the caller can log and
    // move past, rather than as a derivative holding half a document.
    expect($caught)->toBeInstanceOf(WordDocumentException::class)
        ->and(strlen($truncated))->toBe(900);
});

/**
 * The local branch installs its own `set_error_handler` around
 * `file_get_contents`, and both halves of that are load-bearing: the frame has
 * to come back off the stack, and it has to actually be there — PHP 8.4+
 * no longer honours `@` for this call, so dropping the handler turns a
 * missing file into a raw `E_WARNING` on the way to Spora's log.
 */
it('leaves the error-handler stack balanced and still suppressing on both storage paths', function () {
    $container = WordMediaArchive::container();
    $producer  = WordMediaArchive::extractor($container);
    $docx      = DocxFixtures::docx();

    $sentinel = new ErrorRecorder();
    set_error_handler($sentinel, E_ALL);

    $leaked      = [];
    $unreadable  = null;

    try {
        $producer->produce(WordMediaArchive::docxAsset($docx), 'md');
        $leaked['data_url'] = ErrorHandlerStack::leakedFramesAbove($sentinel);

        // Local mode is the path that installs its own handler around
        // `file_get_contents`; data_url is the path that only goes through
        // the deprecation suppressor. Both must come back balanced.
        $local = WordMediaArchive::localDocxAsset($container, $docx);
        $producer->produce($local['asset'], 'md');
        $leaked['local'] = ErrorHandlerStack::leakedFramesAbove($sentinel);

        // The half a balance check alone cannot see: a frame that was never
        // installed also leaves the stack balanced. An empty local file is
        // the only state that reaches the read itself, and it must fail as a
        // clean `WordRuntimeException` rather than as a warning reaching the
        // sentinel.
        // A local file that exists but cannot be read. Both weaker states miss
        // this: a *missing* file is caught by `LocalAssetStore` before the
        // producer's read runs, and a *zero-byte* file reads cleanly and
        // raises no warning at all. Only an unreadable-but-present file
        // reaches the `file_get_contents` whose suppression is under test.
        $denied = WordMediaArchive::localDocxAsset($container, $docx);
        $path   = (string) $denied['store']->readFromAsset($denied['asset'])['path'];
        chmod($path, 0000);
        try {
            $producer->produce($denied['asset'], 'md');
        } catch (WordDocumentException $e) {
            $unreadable = $e;
        } finally {
            chmod($path, 0644);
        }
    } finally {
        restore_error_handler();
    }

    // A frame left above the sentinel would keep intercepting diagnostics
    // for the rest of the worker's life — silently muting every later error.
    expect($leaked)->toBe(['data_url' => 0, 'local' => 0])
        // No `E_WARNING` reached the sentinel. This is the half that makes
        // the handler load-bearing rather than decorative: without it the
        // read's own warning sails past into Spora's log as a raw "failed to
        // open stream" line on every unreadable local asset.
        ->and(array_column($sentinel->entries(), 'errno'))->not->toContain(E_WARNING)
        // And the failure is still the plugin's own hierarchy, not a raw
        // PHP warning leaking past the reader.
        ->and($unreadable)->toBeInstanceOf(WordDocumentException::class)
        ->and($unreadable->getMessage())->toContain('local file is unreadable');
});

/**
 * The two producers are separate classes with separate ctor dependencies, so
 * nothing in PHP would stop one being renamed onto the other's identity and
 * quietly collapsing a round trip onto a single derivative row. This is the
 * cheapest place to catch that.
 */
it('does not share an operation name with the render producer', function () {
    $extract = WordMediaArchive::extractor(WordMediaArchive::container());
    $render  = WordMediaArchive::producer(WordMediaArchive::container());

    expect($extract->operationName())->not->toBe($render->operationName())
        // Same plugin, different seam: the attribution column is
        // `producer_plugin`, and the two rows are only distinguishable by
        // the operation half of the natural key.
        ->and($extract->pluginSlug())->toBe($render->pluginSlug());
});
