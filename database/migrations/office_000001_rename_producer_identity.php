<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;

/**
 * The plugin's own identity changed: `spora-plugin-word` became
 * `spora-plugin-office`, and its two operations became `office.render` and
 * `office.extract`.
 *
 * The values in the rows are **not** what the slug suggests. `pluginSlug()`
 * returns the Composer **package** name (`spora-plugin-word`), not the
 * manifest slug (`word`), and `operationName()` returns the short slug
 * (`word.render`) — so the rows carry two different vocabularies and both of
 * them changed.
 *
 * Both halves of the pair have to move together. The natural key of a
 * derivative is `(parent_id, format, producer_plugin, producer_operation)`,
 * and `MediaDerivativeService` looks the row up by exactly that: a derivative
 * whose `producer_plugin` was rewritten while its `producer_operation` was not
 * no longer matches the key, so the service mints a **second** derivative
 * instead of refreshing the one that is there.
 *
 * The join row carries `producer_plugin` and `producer_operation`; the
 * derivative's own `media_assets` row carries `plugin_slug` and `tool_name`,
 * and the service writes the same two values into it. Both rows are rewritten,
 * or the attribution and the join would name two different plugins.
 *
 * Idempotent: a re-run finds nothing left to rewrite. The rewrite is scoped
 * to this plugin's own vocabulary, so a derivative of any other plugin, and an
 * asset ingested by the `media` tool rather than rendered by one, are left
 * alone.
 */
return new class extends Migration {
    /** The Composer package name the rows carried before the rename. */
    private const PLUGIN_BEFORE = 'spora-plugin-word';

    /** The Composer package name the rows carry now. */
    private const PLUGIN_AFTER = 'spora-plugin-office';

    /**
     * The operation names, and what each became.
     *
     * `word.render` and `word.extract` belong to this plugin alone — the only
     * other producer in the suite names its operations `typst.render` — so a
     * rewrite keyed on them touches nothing else.
     *
     * @var array<string, string>
     */
    private const OPERATIONS = [
        'word.render'  => 'office.render',
        'word.extract' => 'office.extract',
    ];

    public function up(): void
    {
        $this->rewrite(self::PLUGIN_BEFORE, self::PLUGIN_AFTER, self::OPERATIONS);
    }

    public function down(): void
    {
        $this->rewrite(self::PLUGIN_AFTER, self::PLUGIN_BEFORE, [
            'office.render'  => 'word.render',
            'office.extract' => 'word.extract',
        ]);
    }

    /**
     * @param array<string, string> $operations the operation names, and what each became
     */
    private function rewrite(string $pluginFrom, string $pluginTo, array $operations): void
    {
        Capsule::table('media_derivatives')
            ->where('producer_plugin', $pluginFrom)
            ->update(['producer_plugin' => $pluginTo]);

        Capsule::table('media_assets')
            ->where('plugin_slug', $pluginFrom)
            ->update(['plugin_slug' => $pluginTo]);

        foreach ($operations as $from => $to) {
            Capsule::table('media_derivatives')
                ->where('producer_operation', $from)
                ->update(['producer_operation' => $to]);

            Capsule::table('media_assets')
                ->where('tool_name', $from)
                ->update(['tool_name' => $to]);
        }
    }
};
