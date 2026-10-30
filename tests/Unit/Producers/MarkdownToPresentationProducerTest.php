<?php

declare(strict_types=1);

use Spora\Plugins\Office\Exceptions\OfficeDocumentException;
use Spora\Plugins\Office\Exceptions\OfficeInvalidArgumentException;
use Spora\Plugins\Office\Services\OfficeConversion;
use Spora\Plugins\Office\Tests\Support\DocxFixtures;
use Spora\Plugins\Office\Tests\Support\OfficeMediaArchive;

/**
 * The four values are not decoration: `pluginSlug()` and `operationName()`
 * are persisted into `media_derivatives` and are half of that table's
 * natural key, so renaming either orphans every derivative already written
 * instead of refreshing it.
 */
it('declares the identity the media_derivatives natural key is built from', function () {
    $producer = OfficeMediaArchive::deckProducer(OfficeMediaArchive::container());

    expect($producer->pluginSlug())->toBe('spora-plugin-office')
        ->and($producer->operationName())->toBe('presentation.render')
        ->and($producer->supportedDerivativeFormats())->toBe(['pptx'])
        ->and($producer->supportedSourceFormats())->toBe(['text/markdown', 'md', 'markdown'])
        ->and(strlen((string) $producer->supportedDerivativeFormats()[0]))->toBeLessThanOrEqual(16);
});

it('renders a stored markdown asset into a real pptx', function () {
    $producer = OfficeMediaArchive::deckProducer(OfficeMediaArchive::container());

    $output = $producer->produce(
        OfficeMediaArchive::dataUrlAsset("# Title\n\nSome words.\n\n---\n\n# Second slide\n"),
        'pptx',
    );

    // `PK` is the zip local-file header: proof the renderer produced an
    // archive rather than a string that merely claims to be one.
    expect(substr($output->bytes, 0, 2))->toBe('PK')
        ->and(strlen($output->bytes))->toBeGreaterThan(1000)
        ->and($output->mime)->toBe(OfficeConversion::PPTX_MIME)
        ->and($output->width)->toBeNull()
        ->and($output->height)->toBeNull()
        ->and($output->durationSeconds)->toBeNull();
});

it('rejects a format it cannot emit before it touches the asset', function () {
    $producer = OfficeMediaArchive::deckProducer(OfficeMediaArchive::container());
    $caught   = null;

    try {
        $producer->produce(OfficeMediaArchive::externalAsset(), 'docx');
    } catch (OfficeInvalidArgumentException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(OfficeInvalidArgumentException::class)
        ->and($caught->getMessage())->toContain('docx')
        ->and($caught->getMessage())->toContain('pptx');
});

it('refuses a deck that names a theme the media library does not hold', function () {
    $producer = OfficeMediaArchive::deckProducer(OfficeMediaArchive::container());
    $caught   = null;

    try {
        $producer->produce(
            OfficeMediaArchive::dataUrlAsset("# Title\n", principalId: 7),
            'pptx',
            ['theme' => 'no-such-theme'],
        );
    } catch (OfficeDocumentException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(OfficeDocumentException::class)
        ->and($caught->getMessage())->toContain('no-such-theme');
});

it('draws a deck in the theme the media library holds', function () {
    $container = OfficeMediaArchive::container();

    OfficeMediaArchive::library($container)->withTheme(7, 'house', [
        'theme.yml' => "name: House\nauthor: House Style Desk\n",
        'layouts.yml' => "layouts:\n  content: Title and Content\n",
    ]);

    $producer = OfficeMediaArchive::deckProducer($container);

    $output = $producer->produce(
        OfficeMediaArchive::dataUrlAsset("---\ntheme: house\n---\n\n# Title\n", principalId: 7),
        'pptx',
    );

    // The theme's author is the deck's creator when the deck names none, so
    // the metadata is where the theme it was drawn in is visible.
    expect(DocxFixtures::partOf($output->bytes, 'docProps/core.xml'))
        ->toContain('House Style Desk');
});
