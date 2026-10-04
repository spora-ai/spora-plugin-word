<?php

declare(strict_types=1);

use Spora\Plugins\Word\Refiners\WordDocxMimeRefiner;
use Spora\Plugins\Word\Services\WordConversion;
use Spora\Plugins\Word\Tests\Support\DeprecationFilter;
use Spora\Plugins\Word\Tests\Support\DocxFixtures;
use Spora\Plugins\Word\Tests\Support\ErrorRecorder;
use Spora\Services\MediaArchive\MediaMimeRefinerDiscovery;
use Spora\Services\MediaArchive\MimeSniffer;

/**
 * The parts a sibling OOXML package carries that have nothing to do with
 * the document itself. Present so the "another flavour" fixtures look like
 * real Office files rather than like arbitrary zips.
 *
 * @var array<string, string>
 */
$ooxmlSiblings = [
    '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8"?><Types/>',
    '_rels/.rels'         => '<?xml version="1.0" encoding="UTF-8"?><Relationships/>',
];

beforeEach(function () {
    DeprecationFilter::silencePhpWord();
});

afterEach(function () {
    DeprecationFilter::restore();
});

it('upgrades a coarse zip verdict for a real Word package', function () {
    $refiner = new WordDocxMimeRefiner();

    expect($refiner->refine(DocxFixtures::docx(), 'report.docx', DocxFixtures::ZIP_MIME))
        ->toBe(WordConversion::DOCX_MIME);
});

it('returns an already-correct docx verdict unchanged', function () {
    $refiner = new WordDocxMimeRefiner();
    $docx    = DocxFixtures::docx();

    // Not a no-op by accident: the DOCX MIME is itself one of the candidate
    // inputs, so a refiner that only promoted zips would leave this alone by
    // omission. Re-stating it pins the contract that a correct verdict
    // survives a round through the registry.
    expect($refiner->refine($docx, 'report.docx', WordConversion::DOCX_MIME))
        ->toBe(WordConversion::DOCX_MIME);
});

it('declines a zip that carries no word document part', function () {
    $refiner = new WordDocxMimeRefiner();

    $zip = DocxFixtures::zipContaining([
        'notes.txt' => 'a plain archive',
        'readme.md' => '# not a document',
    ]);

    expect($refiner->refine($zip, 'bundle.zip', DocxFixtures::ZIP_MIME))->toBeNull();
});

it('declines another OOXML flavour so spreadsheets are not labelled as Word', function () use ($ooxmlSiblings) {
    $refiner = new WordDocxMimeRefiner();

    $spreadsheet = DocxFixtures::zipContaining(
        $ooxmlSiblings + ['xl/workbook.xml' => '<?xml version="1.0"?><workbook/>'],
    );
    $slides = DocxFixtures::zipContaining(
        $ooxmlSiblings + ['ppt/presentation.xml' => '<?xml version="1.0"?><presentation/>'],
    );

    // Both are genuine OOXML packages carrying `[Content_Types].xml`, and
    // both would pass any "is this a zip?" or "is this an Office file?"
    // check. Only the absent `word/document.xml` separates them from Word.
    expect($refiner->refine($spreadsheet, 'book.xlsx', DocxFixtures::ZIP_MIME))->toBeNull()
        ->and($refiner->refine($slides, 'deck.pptx', DocxFixtures::ZIP_MIME))->toBeNull()
        ->and(DocxFixtures::partOf($spreadsheet, 'xl/workbook.xml'))->toContain('<workbook');
});

it('declines bytes that are not a zip at all', function () {
    $refiner = new WordDocxMimeRefiner();

    expect($refiner->refine('plain text, definitely not a zip', 'notes.txt', DocxFixtures::ZIP_MIME))->toBeNull()
        ->and($refiner->refine('', null, DocxFixtures::ZIP_MIME))->toBeNull();
});

it('never opens a payload the sniffer did not call a zip', function () {
    $refiner = new WordDocxMimeRefiner();
    $docx    = DocxFixtures::docx();

    // `application/octet-stream` is `finfo`'s "I cannot name this" verdict.
    // Unzipping arbitrary uploads on its say-so would turn every
    // unrecognised file into a filesystem probe; the refiner only spends
    // I/O on the two container types worth opening.
    expect($refiner->refine($docx, 'report.docx', 'text/plain'))->toBeNull()
        ->and($refiner->refine($docx, 'report.docx', 'application/octet-stream'))->toBeNull()
        ->and($refiner->refine($docx, 'report.docx', 'image/png'))->toBeNull();
});

it('raises no diagnostic while declining, as its contract promises', function () use ($ooxmlSiblings) {
    $refiner  = new WordDocxMimeRefiner();
    $recorder = new ErrorRecorder();
    set_error_handler($recorder, E_ALL);

    try {
        // `ZipArchive::open()` both warns and returns an error code for
        // bytes that are not a zip, so this is the one path where a refiner
        // that forgot its scoped handler would be visible.
        $refiner->refine('not a zip at all', 'x.bin', DocxFixtures::ZIP_MIME);
        $refiner->refine(
            DocxFixtures::zipContaining($ooxmlSiblings + ['xl/workbook.xml' => '<workbook/>']),
            'book.xlsx',
            DocxFixtures::ZIP_MIME,
        );
        $refiner->refine('', null, DocxFixtures::ZIP_MIME);
    } finally {
        restore_error_handler();
    }

    expect($recorder->entries())->toBe([]);
});

it('runs inside MimeSniffer and leaves a non-Word zip alone', function () {
    MediaMimeRefinerDiscovery::add(WordDocxMimeRefiner::class);

    $sniffer = new MimeSniffer();

    // `MimeSniffer` instantiates refiners with `new $class()` — a
    // no-argument constructor is the contract, and reaching the refiner at
    // all proves it holds.
    expect((new ReflectionClass(WordDocxMimeRefiner::class))->getConstructor())->toBeNull();

    // The decline is what makes this an integration check rather than a
    // restatement: a bare zip sniffs as `application/zip` on every libmagic
    // build, so a refiner that wrongly claimed it would flip this verdict.
    $zip = DocxFixtures::zipContaining(['notes.txt' => 'a plain archive']);
    expect($sniffer->sniffFromBytes($zip, 'bundle.zip'))->toBe(DocxFixtures::ZIP_MIME)
        ->and($sniffer->sniffFromBytes(DocxFixtures::docx(), 'report.docx'))
        ->toBe(WordConversion::DOCX_MIME);
});
