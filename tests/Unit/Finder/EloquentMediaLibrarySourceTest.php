<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Spora\Core\Paths;
use Spora\Core\SecurityManager;
use Spora\Plugins\Office\Finder\EloquentMediaLibrarySource;
use Spora\Plugins\Office\Finder\LibraryHalf;
use Spora\Plugins\Office\Finder\MediaLibraryFinder;
use Spora\Plugins\Office\Services\OfficeSourceBytes;
use Spora\Plugins\Office\Tests\Support\OfficeMediaArchive;
use Spora\Services\DatabaseAssetStore;
use Spora\Services\LocalAssetStore;

/**
 * The source over a real schema.
 *
 * The folders table arrives with the core release that carries the folders
 * migration; until that release exists the test makes the columns the source
 * reads, and the guard means core's own table is used the moment it is there.
 */
beforeEach(function () {
    $schema = Capsule::schema();

    if (!$schema->hasTable('media_folders')) {
        $schema->create('media_folders', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('principal_id');
            $table->uuid('parent_id')->nullable();
            $table->string('name', 255);
            $table->string('path', 512);
            $table->timestamps();
        });
    }

    if (!$schema->hasColumn('media_assets', 'folder_id')) {
        $schema->table('media_assets', static function (Blueprint $table): void {
            $table->uuid('folder_id')->nullable();
        });
    }
});

/**
 * A principal, a folder and the assets in it.
 *
 * @return array{folderId: string, manifest: string, logo: string}
 */
function seedThemeFolder(int $principalId = 7, string $name = 'Corporate'): array
{
    Capsule::table('principals')->insert([
        'id'         => $principalId,
        'type'       => 'user',
        'created_at' => '2026-10-30 00:00:00',
        'updated_at' => '2026-10-30 00:00:00',
    ]);

    $folderId = '3f7c1d2e-4a5b-4c6d-8e9f-0a1b2c3d4e5f';

    Capsule::table('media_folders')->insert([
        'id'           => $folderId,
        'principal_id' => $principalId,
        'parent_id'    => null,
        'name'         => $name,
        'path'         => '/' . $name,
        'created_at'   => '2026-10-30 00:00:00',
        'updated_at'   => '2026-10-30 00:00:00',
    ]);

    $container = OfficeMediaArchive::container();

    $manifest = OfficeMediaArchive::ingestMarkdown($container, "name: Corporate\n", 'theme.yml');
    $logo     = OfficeMediaArchive::ingestMarkdown($container, 'PNGBYTES', 'logo.png');

    Capsule::table('media_assets')->where('id', $manifest->id)->update([
        'principal_id' => $principalId,
        'folder_id'    => $folderId,
        'filename'     => 'theme.yml',
    ]);

    Capsule::table('media_assets')->where('id', $logo->id)->update([
        'principal_id' => $principalId,
        'filename'     => 'logo.png',
    ]);

    return ['folderId' => $folderId, 'manifest' => "name: Corporate\n", 'logo' => 'PNGBYTES'];
}

/**
 * The source over core's tables, and a finder that reads through it.
 */
function finderFor(int $principalId = 7, LibraryHalf $half = LibraryHalf::Slides): MediaLibraryFinder
{
    $source = new EloquentMediaLibrarySource(new OfficeSourceBytes(
        new DatabaseAssetStore(),
        new LocalAssetStore(
            new Paths(sys_get_temp_dir()),
            new SecurityManager(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
        ),
    ));

    return new MediaLibraryFinder($source, $principalId, $half);
}

it('lists the themes of the principal', function () {
    seedThemeFolder();

    expect(finderFor()->themes())->toBe(['Corporate']);
});

it('lists the files of a theme by its name and by its id', function () {
    $seed = seedThemeFolder();

    expect(finderFor()->files('Corporate'))->toBe(['theme.yml'])
        ->and(finderFor()->files($seed['folderId']))->toBe(['theme.yml']);
});

it('answers a theme file and an asset by their names', function () {
    $seed = seedThemeFolder();

    expect(finderFor()->read('Corporate/theme.yml', 'theme'))->toBe($seed['manifest'])
        ->and(finderFor()->read('logo.png', 'picture'))->toBe($seed['logo']);
});

it('answers null for a file the principal does not hold', function () {
    seedThemeFolder();

    expect(finderFor()->read('Corporate/nothing.yml', 'theme'))->toBeNull()
        ->and(finderFor()->read('nothing.png', 'picture'))->toBeNull()
        ->and(finderFor()->files('NoSuchTheme'))->toBe([]);
});

it('answers null for an asset whose bytes it cannot read', function () {
    seedThemeFolder();

    $container = OfficeMediaArchive::container();
    $external  = OfficeMediaArchive::ingestMarkdown($container, 'PNGBYTES', 'external.png');

    Capsule::table('media_assets')->where('id', $external->id)->update([
        'principal_id' => 7,
        'storage_mode' => 'external',
        'payload'      => null,
    ]);

    // A file it cannot read is a file that is not there, as far as the
    // library is concerned: a finder answers and never refuses.
    expect(finderFor()->read('external.png', 'picture'))->toBeNull();
});

it('answers nothing on a core that has no folders', function () {
    seedThemeFolder();

    // A core without the folders table has no media themes, and saying so is
    // the honest answer: a document that names a theme is then drawn in the
    // theme the renderer itself ships.
    Capsule::schema()->drop('media_folders');

    expect(finderFor()->themes())->toBe([])
        ->and(finderFor()->read('Corporate/theme.yml', 'theme'))->toBeNull()
        ->and(finderFor()->files('Corporate'))->toBe([]);
});

it('never answers for another principal', function () {
    seedThemeFolder(7, 'Corporate');

    expect(finderFor(8)->themes())->toBe([])
        ->and(finderFor(8)->read('Corporate/theme.yml', 'theme'))->toBeNull()
        ->and(finderFor(8)->read('logo.png', 'picture'))->toBeNull();
});
