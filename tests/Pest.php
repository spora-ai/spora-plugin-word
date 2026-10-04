<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Pest Bootstrap
|--------------------------------------------------------------------------
|
| Mirrors spora-plugin-typst's pattern: define BASE_PATH, then hand-roll a
| `uses(...)` block that installs the full core migration set into a
| per-process SQLite file and rolls back each test in afterEach.
|
| afterEach resets **all three** discovery registries. They are in-process
| statics, and Pest's parallel workers do not share memory — but serial
| runs do, so a leaked registration from one test would silently change
| which converter/producer/refiner the next test resolves.
|
*/

use Delight\Auth\Auth as DelightAuth;
use Illuminate\Database\Capsule\Manager as Capsule;
use Mockery as M;
use Spora\Auth\AuthService;
use Spora\Core\Database;
use Spora\Core\DatabaseSchemaInstaller;
use Spora\Services\MediaArchive\MediaConverterDiscovery;
use Spora\Services\MediaArchive\MediaDerivativeProducerDiscovery;
use Spora\Services\MediaArchive\MediaMimeRefinerDiscovery;

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require_once BASE_PATH . '/vendor/autoload.php';

set_error_handler(static function (...$handlerArgs): bool {
    [$errno, , $errfile] = $handlerArgs;

    if ($errno === E_DEPRECATED
        && (str_contains($errfile, DIRECTORY_SEPARATOR . 'delight-im' . DIRECTORY_SEPARATOR)
            || str_contains($errfile, DIRECTORY_SEPARATOR . 'phpoffice' . DIRECTORY_SEPARATOR . 'phpword' . DIRECTORY_SEPARATOR))
    ) {
        return true;
    }

    return false;
}, E_DEPRECATED);

/**
 * Boot a fresh SQLite database and return a ready-to-use AuthService.
 * Throttling is disabled so tests never hit rate limits.
 */
function bootAuthLayer(): AuthService
{
    $pdo  = Capsule::connection()->getPdo();
    $auth = new DelightAuth($pdo, null, null, false /* throttling off */);

    return new AuthService($auth);
}

function clearSession(): void
{
    $_SESSION = [];
}

uses()
    ->beforeEach(function () {
        Database::resetBootState();
        $tmpDb = sys_get_temp_dir() . '/spora-plugin-word-' . bin2hex(random_bytes(4)) . '.sqlite';
        $db = new Database(['db_driver' => 'sqlite', 'db_path' => $tmpDb]);
        $db->bootDatabaseConnectionOnly();

        // The plugin owns no tables, but every test touching MediaAsset /
        // media_derivatives / users needs the full core schema in place.
        $installer = new DatabaseSchemaInstaller(null, null, null);
        $installer->install();

        Capsule::connection()->beginTransaction();
    })
    ->afterEach(function () {
        if (Capsule::connection()->transactionLevel() > 0) {
            Capsule::connection()->rollBack();
        }
        Database::resetBootState();
        clearSession();
        MediaDerivativeProducerDiscovery::reset();
        MediaConverterDiscovery::reset();
        MediaMimeRefinerDiscovery::reset();
        M::close();
    })
    ->in(__DIR__);
