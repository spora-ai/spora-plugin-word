<?php

declare(strict_types=1);

use Spora\Plugins\Word\Exceptions\WordDocumentException;
use Spora\Plugins\Word\Exceptions\WordInvalidArgumentException;
use Spora\Plugins\Word\Exceptions\WordRuntimeException;
use Spora\Plugins\Word\Services\WordConversion;
use Spora\Plugins\Word\Tests\Support\DocxFixtures;
use Spora\Plugins\Word\Tests\Support\ErrorHandlerStack;
use Spora\Plugins\Word\Tests\Support\ErrorRecorder;
use Spora\Plugins\Word\Tests\Support\WordMediaArchive;

/**
 * The four values are not decoration: `pluginSlug()` and `operationName()`
 * are persisted into `media_derivatives` and are half of that table's
 * natural key, so renaming either orphans every derivative already written
 * instead of refreshing it. The source list is what
 * `MediaDerivativeService::findProducer()` matches the parent's MIME and
 * extension against, and the format list lands in the 16-character
 * `media_derivatives.format` column.
 */
it('declares the identity the media_derivatives natural key is built from', function () {
    $producer = WordMediaArchive::producer(WordMediaArchive::container());

    expect($producer->pluginSlug())->toBe('spora-plugin-word')
        ->and($producer->operationName())->toBe('word.render')
        ->and($producer->supportedDerivativeFormats())->toBe(['docx'])
        ->and($producer->supportedSourceFormats())->toBe(['text/markdown', 'md', 'markdown'])
        ->and(strlen((string) $producer->supportedDerivativeFormats()[0]))->toBeLessThanOrEqual(16);
});

it('renders a stored markdown asset into a real docx', function () {
    $producer = WordMediaArchive::producer(WordMediaArchive::container());

    $output = $producer->produce(WordMediaArchive::dataUrlAsset(DocxFixtures::SAMPLE_MARKDOWN), 'docx');

    // `PK` is the zip local-file header: proof the renderer produced an
    // archive rather than a string that merely claims to be one.
    expect(substr($output->bytes, 0, 2))->toBe('PK')
        ->and(strlen($output->bytes))->toBeGreaterThan(1000)
        // The derivative is a document, not an image or a clip — a wrong
        // value here would land in `media_assets.width` / `height`.
        ->and($output->mime)->toBe(WordConversion::DOCX_MIME)
        ->and($output->width)->toBeNull()
        ->and($output->height)->toBeNull()
        ->and($output->durationSeconds)->toBeNull();
});

it('reads a local-mode asset back off disk', function () {
    $container = WordMediaArchive::container();
    $producer  = WordMediaArchive::producer($container);

    // The second of the producer's two storage branches. It installs its
    // own `set_error_handler` around the `file_get_contents`, which is why
    // this mode is exercised at all rather than only reasoned about.
    $local  = WordMediaArchive::localAsset($container, DocxFixtures::SAMPLE_MARKDOWN);
    $output = $producer->produce($local['asset'], 'docx');

    expect(substr($output->bytes, 0, 2))->toBe('PK')
        ->and($output->mime)->toBe(WordConversion::DOCX_MIME)
        // The row really is disk-backed: the store the producer reads through
        // resolves it to a file the fixture wrote, byte for byte.
        ->and(file_get_contents($local['store']->readFromAsset($local['asset'])['path']))
        ->toBe(DocxFixtures::SAMPLE_MARKDOWN);
});

it('rejects a format it cannot emit before it touches the asset', function () {
    $producer = WordMediaArchive::producer(WordMediaArchive::container());
    $caught   = null;

    // `external` has no readable bytes, so reaching `WordRuntimeException`
    // instead of `WordInvalidArgumentException` would mean the format gate
    // ran too late. The order is the contract: an LLM asking for `pdf` must
    // be told the format is wrong, not that its document is missing.
    try {
        $producer->produce(WordMediaArchive::externalAsset(), 'pdf');
    } catch (WordInvalidArgumentException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(WordInvalidArgumentException::class)
        // The supported list travels with the failure so the LLM can retry
        // without a second round trip.
        ->and($caught->getMessage())->toContain('pdf')
        ->and($caught->getMessage())->toContain('docx');
});

it('tolerates a padded or mixed-case format identifier', function () {
    $producer = WordMediaArchive::producer(WordMediaArchive::container());

    $output = $producer->produce(WordMediaArchive::dataUrlAsset(DocxFixtures::SAMPLE_MARKDOWN), ' DOCX ');

    expect($output->mime)->toBe(WordConversion::DOCX_MIME);
});

it('names a remedy that exists for an externally stored asset', function () {
    $producer = WordMediaArchive::producer(WordMediaArchive::container());
    $caught   = null;

    try {
        $producer->produce(WordMediaArchive::externalAsset(), 'docx');
    } catch (WordRuntimeException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(WordRuntimeException::class)
        ->and($caught->getMessage())->toContain('external')
        // The remedy has to be a call the model can actually make. An
        // earlier version named `get_public_url`, but no `media` operation
        // ingests a URL — `MediaIngestRequest::$url` is reachable only from
        // the HTTP controllers — so that instruction sent the model looking
        // for a fetch it could not perform. `create_media` is the real path
        // for text, and it is what this message points at.
        ->and($caught->getMessage())->toContain('create_media');
});

it('refuses a markdown source beyond the 8 MiB ceiling', function () {
    $producer = WordMediaArchive::producer(WordMediaArchive::container());
    // `fabeat/markdown-word` bounds the *reverse* direction and applies no
    // limit here, so the producer is the only thing standing between an
    // oversized generation and a parser that holds the whole syntax tree in
    // memory at once.
    $oversized = str_repeat('a', WordConversion::MAX_MARKDOWN_BYTES + 1);
    $caught    = null;

    try {
        $producer->produce(WordMediaArchive::dataUrlAsset($oversized), 'docx');
    } catch (WordDocumentException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(WordDocumentException::class)
        // Both numbers in the message: the actual size and the ceiling, so
        // an operator can see which side of the line the document landed on.
        ->and($caught->getMessage())->toContain((string) WordConversion::MAX_MARKDOWN_BYTES)
        ->and($caught->getMessage())->toContain((string) (WordConversion::MAX_MARKDOWN_BYTES + 1));
});

it('drops the library decoration only for a strictly true plain option', function () {
    $producer = WordMediaArchive::producer(WordMediaArchive::container());
    $asset    = WordMediaArchive::dataUrlAsset(DocxFixtures::SAMPLE_MARKDOWN);

    $decorated = $producer->produce($asset, 'docx');
    $plain     = $producer->produce($asset, 'docx', ['plain' => true]);
    // `create_derivative` forwards a free-form `options` blob, so a truthy
    // non-boolean must not silently switch the document's styling.
    $truthy    = $producer->produce($asset, 'docx', ['plain' => 'yes']);

    // `IntenseQuote` is the block-quote style `Configuration` adds over
    // Word's own; `withoutDecoration()` is what removes it.
    expect(DocxFixtures::partOf($decorated->bytes, 'word/styles.xml'))->toContain('IntenseQuote')
        ->and(DocxFixtures::partOf($plain->bytes, 'word/styles.xml'))->not->toContain('IntenseQuote')
        ->and(DocxFixtures::partOf($truthy->bytes, 'word/styles.xml'))->toContain('IntenseQuote');
});

it('leaves the error-handler stack balanced on both storage paths', function () {
    $container = WordMediaArchive::container();
    $producer  = WordMediaArchive::producer($container);

    $sentinel = new ErrorRecorder();
    set_error_handler($sentinel, E_ALL);

    $leaked = [];

    try {
        $producer->produce(WordMediaArchive::dataUrlAsset(DocxFixtures::SAMPLE_MARKDOWN), 'docx');
        $leaked['data_url'] = ErrorHandlerStack::leakedFramesAbove($sentinel);

        // Local mode is the path that installs its own handler around
        // `file_get_contents`; data_url is the path that only goes through
        // the deprecation suppressor. Both must come back balanced.
        $local = WordMediaArchive::localAsset($container, DocxFixtures::SAMPLE_MARKDOWN);
        $producer->produce($local['asset'], 'docx');
        $leaked['local'] = ErrorHandlerStack::leakedFramesAbove($sentinel);
    } finally {
        restore_error_handler();
    }

    // A frame left above the sentinel would keep intercepting diagnostics
    // for the rest of the worker's life — silently muting every later error.
    expect($leaked)->toBe(['data_url' => 0, 'local' => 0]);
});
