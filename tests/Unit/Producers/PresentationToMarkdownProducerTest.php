<?php

declare(strict_types=1);

use Spora\Plugins\Office\Exceptions\OfficeInvalidArgumentException;
use Spora\Plugins\Office\Services\OfficeConversion;
use Spora\Plugins\Office\Tests\Support\OfficeMediaArchive;

it('declares the identity the media_derivatives natural key is built from', function () {
    $producer = OfficeMediaArchive::deckExtractor(OfficeMediaArchive::container());

    expect($producer->pluginSlug())->toBe('spora-plugin-office')
        ->and($producer->operationName())->toBe('presentation.extract')
        ->and($producer->supportedDerivativeFormats())->toBe(['md'])
        // The PPTX MIME is the plugin's contribution to the upload allowlist.
        ->and($producer->supportedSourceFormats())->toBe([OfficeConversion::PPTX_MIME, 'pptx']);
});

it('reads a deck back as the markdown it was written from', function () {
    $container = OfficeMediaArchive::container();
    $producer  = OfficeMediaArchive::deckExtractor($container);

    $pptx = OfficeMediaArchive::deckProducer($container)->produce(
        OfficeMediaArchive::dataUrlAsset("# Title\n\nSome words.\n\n---\n\n# Second slide\n"),
        'pptx',
    );

    $output = $producer->produce(OfficeMediaArchive::dataUrlAsset($pptx->bytes, 'deck.pptx'), 'md');

    expect($output->mime)->toBe('text/markdown')
        ->and($output->bytes)->toContain('Title')
        ->and($output->bytes)->toContain('Some words.')
        ->and($output->bytes)->toContain('Second slide')
        ->and($output->width)->toBeNull()
        ->and($output->height)->toBeNull();
});

it('rejects a format it cannot emit before it touches the asset', function () {
    $producer = OfficeMediaArchive::deckExtractor(OfficeMediaArchive::container());
    $caught   = null;

    try {
        $producer->produce(OfficeMediaArchive::externalAsset(), 'docx');
    } catch (OfficeInvalidArgumentException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(OfficeInvalidArgumentException::class)
        ->and($caught->getMessage())->toContain('docx')
        ->and($caught->getMessage())->toContain('md');
});
