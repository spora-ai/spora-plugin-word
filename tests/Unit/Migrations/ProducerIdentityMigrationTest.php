<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Plugins\Office\Tests\Support\OfficeMediaArchive;

/**
 * The migration that rewrites the rows the rename touched.
 *
 * The rows carry the plugin's identity in two vocabularies — the Composer
 * package name and the operation names — and both have to move together, or
 * `MediaDerivativeService` stops finding the derivative it wrote and mints a
 * second one.
 */
/**
 * The migration, as the file returns it.
 *
 * The file returns an anonymous class, so the type of the `require` is what
 * the analyser infers from it — the helper hands the migration back as it is
 * and the caller gets `up()` and `down()` without an annotation.
 *
 * The path is a literal because that is what lets the analyser read the file:
 * the plugin's own `migrationsPath()` is asserted in `OfficePluginTest`.
 */
function officeMigration()
{
    return require __DIR__ . '/../../../database/migrations/office_000001_rename_producer_identity.php';
}

/**
 * A parent asset and a derivative asset, both in the archive.
 *
 * @return array{parent: string, derivative: string}
 */
function seedDerivativeRows(string $pluginSlug, string $operation): array
{
    $container = OfficeMediaArchive::container();

    $parent     = OfficeMediaArchive::ingestMarkdown($container, '# Parent', 'parent.md');
    $derivative = OfficeMediaArchive::ingestMarkdown($container, '# Derivative', 'derivative.md');

    Capsule::table('media_assets')->where('id', $derivative->id)->update([
        'plugin_slug' => $pluginSlug,
        'tool_name'   => $operation,
    ]);

    Capsule::table('media_derivatives')->insert([
        'id'                 => bin2hex(random_bytes(16)),
        'parent_id'          => $parent->id,
        'derivative_id'      => $derivative->id,
        'format'             => 'docx',
        'producer_plugin'    => $pluginSlug,
        'producer_operation' => $operation,
        'created_at'         => '2026-10-30 00:00:00',
        'updated_at'         => '2026-10-30 00:00:00',
    ]);

    return ['parent' => $parent->id, 'derivative' => $derivative->id];
}

it('rewrites the join row and the derivative asset of every operation', function () {
    $render = seedDerivativeRows('spora-plugin-word', 'word.render');
    $extract = seedDerivativeRows('spora-plugin-word', 'word.extract');

    officeMigration()->up();

    $join = Capsule::table('media_derivatives')->where('parent_id', $render['parent'])->first();

    expect($join->producer_plugin)->toBe('spora-plugin-office')
        ->and($join->producer_operation)->toBe('office.render');

    $extracted = Capsule::table('media_derivatives')->where('parent_id', $extract['parent'])->first();

    expect($extracted->producer_plugin)->toBe('spora-plugin-office')
        ->and($extracted->producer_operation)->toBe('office.extract');

    // The derivative's own asset row carries the same two values.
    $asset = Capsule::table('media_assets')->where('id', $render['derivative'])->first();

    expect($asset->plugin_slug)->toBe('spora-plugin-office')
        ->and($asset->tool_name)->toBe('office.render');

    $extractedAsset = Capsule::table('media_assets')->where('id', $extract['derivative'])->first();

    expect($extractedAsset->plugin_slug)->toBe('spora-plugin-office')
        ->and($extractedAsset->tool_name)->toBe('office.extract');
});

it('leaves every other plugin and every ingested asset alone', function () {
    $other = seedDerivativeRows('spora-plugin-typst', 'typst.render');

    $container = OfficeMediaArchive::container();
    $ingested = OfficeMediaArchive::ingestMarkdown($container, '# Ingested', 'ingested.md');

    Capsule::table('media_assets')->where('id', $ingested->id)->update([
        'plugin_slug' => 'spora-core',
        'tool_name'   => 'create_media',
    ]);

    officeMigration()->up();

    $join = Capsule::table('media_derivatives')->where('parent_id', $other['parent'])->first();

    expect($join->producer_plugin)->toBe('spora-plugin-typst')
        ->and($join->producer_operation)->toBe('typst.render');

    $asset = Capsule::table('media_assets')->where('id', $other['derivative'])->first();

    expect($asset->plugin_slug)->toBe('spora-plugin-typst')
        ->and($asset->tool_name)->toBe('typst.render');

    $ingestedAsset = Capsule::table('media_assets')->where('id', $ingested->id)->first();

    expect($ingestedAsset->plugin_slug)->toBe('spora-core')
        ->and($ingestedAsset->tool_name)->toBe('create_media');
});

it('rewrites nothing on a second run', function () {
    $rows = seedDerivativeRows('spora-plugin-word', 'word.render');

    $migration = officeMigration();
    $migration->up();
    $migration->up();

    $join = Capsule::table('media_derivatives')->where('parent_id', $rows['parent'])->first();

    expect($join->producer_plugin)->toBe('spora-plugin-office')
        ->and($join->producer_operation)->toBe('office.render');
});

it('rewrites the rows back when it is rolled back', function () {
    $rows = seedDerivativeRows('spora-plugin-word', 'word.render');

    $migration = officeMigration();
    $migration->up();
    $migration->down();

    $join = Capsule::table('media_derivatives')->where('parent_id', $rows['parent'])->first();

    expect($join->producer_plugin)->toBe('spora-plugin-word')
        ->and($join->producer_operation)->toBe('word.render');
});
