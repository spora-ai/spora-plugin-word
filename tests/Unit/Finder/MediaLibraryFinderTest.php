<?php

declare(strict_types=1);

use MarkdownPresentation\Files\FileFinder as PresentationFileFinder;
use MarkdownWord\Files\FileFinder as WordFileFinder;
use Spora\Plugins\Office\Finder\LibraryHalf;
use Spora\Plugins\Office\Finder\MediaLibraryFinder;
use Spora\Plugins\Office\Tests\Support\FakeMediaLibrary;
use Symfony\Component\Yaml\Yaml;

/**
 * The finder is a trust boundary: it answers with bytes for a name a document
 * wrote. These are the rules that keep the answer inside the principal that
 * asked, and the shape of the answer the two halves of the library expect.
 */
it('answers both halves of the library', function () {
    $library = new FakeMediaLibrary();

    expect(new MediaLibraryFinder($library, 7, LibraryHalf::Word))
        ->toBeInstanceOf(WordFileFinder::class)
        ->and(new MediaLibraryFinder($library, 7, LibraryHalf::Slides))
        ->toBeInstanceOf(PresentationFileFinder::class);
});

it('answers a theme manifest by the folder name', function () {
    $library = new FakeMediaLibrary();
    $library->withTheme(7, 'corporate', ['theme.yml' => "name: Corporate\n"]);

    $finder = new MediaLibraryFinder($library, 7, LibraryHalf::Slides);

    expect($finder->read('corporate/theme.yml', 'theme'))->toBe("name: Corporate\n");
});

it('serves the word half a projection of the slides manifest', function () {
    $manifest = "name: Corporate\n"
        . "description: The house theme\n"
        . "author: Ops\n"
        . "dark: true\n"
        . "colors:\n"
        . "  accent1: '#4C1D95'\n";

    $library = new FakeMediaLibrary();
    $library->withTheme(7, 'corporate', ['theme.yml' => $manifest]);

    $finder = new MediaLibraryFinder($library, 7, LibraryHalf::Word);
    $projected = $finder->read('corporate/theme.yml', 'theme');

    // The word half refuses a key it does not know, so the manifest it is
    // handed carries the three keys it reads and nothing else.
    expect($projected)->not->toBeNull()
        ->and(Yaml::parse((string) $projected))->toBe([
            'name' => 'Corporate',
            'description' => 'The house theme',
            'author' => 'Ops',
        ]);
});

it('serves the slides half the manifest as it is written', function () {
    $manifest = "name: Corporate\ndark: true\ncolors:\n  accent1: '#4C1D95'\n";

    $library = new FakeMediaLibrary();
    $library->withTheme(7, 'corporate', ['theme.yml' => $manifest]);

    $finder = new MediaLibraryFinder($library, 7, LibraryHalf::Slides);

    expect($finder->read('corporate/theme.yml', 'theme'))->toBe($manifest);
});

it('answers a theme named by the folder id as well as by its name', function () {
    $library = new FakeMediaLibrary();
    $library->withTheme(7, '3f7c1d2e-4a5b-4c6d-8e9f-0a1b2c3d4e5f', ['theme.yml' => "name: Pinned\n"]);

    $finder = new MediaLibraryFinder($library, 7, LibraryHalf::Slides);

    // The folder's id is a name too: a task can pin a theme by a value that
    // survives the operator renaming the folder.
    expect($finder->read('3f7c1d2e-4a5b-4c6d-8e9f-0a1b2c3d4e5f/theme.yml', 'theme'))
        ->toBe("name: Pinned\n");
});

it('never answers for another principal', function () {
    $library = new FakeMediaLibrary();
    $library->withTheme(8, 'corporate', ['theme.yml' => "name: Other tenant\n"]);
    $library->withAsset(8, 'logo.png', 'THEIRS');

    $finder = new MediaLibraryFinder($library, 7, LibraryHalf::Slides);

    expect($finder->read('corporate/theme.yml', 'theme'))->toBeNull()
        ->and($finder->read('logo.png', 'picture'))->toBeNull()
        ->and($finder->themes())->toBe([])
        ->and($finder->files('corporate'))->toBe([]);
});

it('refuses a theme name that is a path', function () {
    $library = new FakeMediaLibrary();

    // A folder named `.` or `..` is a name the database allows and a
    // document must not reach: they are the directories above the one the
    // theme was written from, not folders of the principal's own.
    $library->withTheme(7, '..', ['theme.yml' => "name: Sneaky\n"]);
    $library->withTheme(7, '.', ['theme.yml' => "name: Sneaky\n"]);
    $library->withTheme(7, 'corporate', ['theme.yml' => "name: Corporate\n"]);

    $finder = new MediaLibraryFinder($library, 7, LibraryHalf::Slides);

    expect($finder->read('../theme.yml', 'theme'))->toBeNull()
        ->and($finder->read('./theme.yml', 'theme'))->toBeNull()
        ->and($finder->files('..'))->toBe([])
        ->and($finder->files('.'))->toBe([])
        // The name is refused before the library is asked about it, so a
        // path-shaped name never reaches the storage at all.
        ->and($library->askedThemes())->toBe([]);
});

it('answers a picture and a document by the asset filename', function () {
    $library = new FakeMediaLibrary();
    $library->withAsset(7, 'logo.png', 'THE PICTURE');
    $library->withAsset(7, 'report.dotx', 'THE TEMPLATE');

    $finder = new MediaLibraryFinder($library, 7, LibraryHalf::Word);

    expect($finder->read('logo.png', 'picture'))->toBe('THE PICTURE')
        ->and($finder->read('report.dotx', 'document'))->toBe('THE TEMPLATE')
        // The slides half asks for its templates file with no role at all.
        ->and($finder->read('slides.md'))->toBeNull();
});

it('answers null for a file that is not there', function () {
    $library = new FakeMediaLibrary();
    $finder = new MediaLibraryFinder($library, 7, LibraryHalf::Word);

    expect($finder->read('nothing/theme.yml', 'theme'))->toBeNull()
        ->and($finder->read('nothing.png', 'picture'))->toBeNull()
        ->and($finder->read('nothing', 'document'))->toBeNull()
        ->and($finder->files('nothing'))->toBe([]);
});

it('never answers a path', function () {
    $library = new FakeMediaLibrary();
    $library->withTheme(7, 'corporate', ['theme.yml' => "name: Corporate\n"]);
    $library->withAsset(7, 'logo.png', 'THE PICTURE');

    $finder = new MediaLibraryFinder($library, 7, LibraryHalf::Word);

    // The media library holds bytes and no files, so the library stages what
    // it cannot be handed as bytes into a file of its own.
    expect($finder->path('corporate/theme.yml', 'theme'))->toBeNull()
        ->and($finder->path('logo.png', 'picture'))->toBeNull();
});

it('lists the themes of the principal and the files of a theme', function () {
    $library = new FakeMediaLibrary();
    $library->withTheme(7, 'corporate', ['theme.yml' => "name: Corporate\n", 'styles.yml' => "paragraph: {}\n"]);
    // A folder without a manifest is not a theme.
    $library->withTheme(7, 'not-a-theme', ['notes.txt' => 'hello']);

    $finder = new MediaLibraryFinder($library, 7, LibraryHalf::Slides);

    expect($finder->themes())->toBe(['corporate'])
        ->and($finder->files('corporate'))->toBe(['styles.yml', 'theme.yml']);
});
