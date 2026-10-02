<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Plugin-local Bootstrap
|--------------------------------------------------------------------------
|
| Mirrors spora-plugin-typst's bootstrap: the BASE_PATH constant (so
| plugin code that walks up to the spora-core install resolves
| correctly), and an E_DEPRECATED filter for delight-im.
|
| The plugin doesn't own any tables — every table it touches
| (`media_assets`, `media_derivatives`, `users`, `agents`, `principals`)
| lives in spora-core. `DatabaseSchemaInstaller::install()` is idempotent
| on `schema_versions`, so successive runs short-circuit.
|
| phpoffice/phpword 1.4 emits `Using null as an array offset is deprecated`
| from `PhpWord\Style::getStyle()` on every render — around a dozen
| notices for a trivial document. Production code suppresses them at the
| call site in {@see \Spora\Plugins\Word\Services\WordConversion}; this
| bootstrap covers the *test* runs, where the production suppressor is
| deliberately not exercised on every single assertion.
|
*/

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
