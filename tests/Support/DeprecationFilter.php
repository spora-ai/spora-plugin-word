<?php

declare(strict_types=1);

namespace Spora\Plugins\Word\Tests\Support;

/**
 * Silences `phpoffice/phpword`'s deprecations for the duration of a test.
 *
 * `tests/bootstrap.php` and `tests/Pest.php` each install this filter, but
 * PHPUnit registers its own error handler *after* the bootstrap, so theirs
 * ends up below PHPUnit's and never sees the diagnostic. The visible effect
 * is that a test which renders a fixture straight through `MarkdownToWord`
 * reports a dozen "deprecated" markers — and rendering straight through
 * `MarkdownToWord` is exactly what {@see DocxFixtures} has to do, because a
 * fixture must not be built by the code under test.
 *
 * Those notices belong to a vendor library and no assertion in this suite is
 * about them, so the tests that build a fixture filter them out locally.
 *
 * Every `silencePhpWord()` needs its matching `restore()` in `afterEach`:
 * a frame left installed would sit above the handler the suppressor tests
 * install on purpose and swallow the deprecations they are there to observe.
 */
final class DeprecationFilter
{
    public static function silencePhpWord(): void
    {
        set_error_handler(
            static function (int $errno, string $errstr, string $errfile): bool {
                return $errno === E_DEPRECATED
                    && str_contains(
                        $errfile,
                        DIRECTORY_SEPARATOR . 'phpoffice' . DIRECTORY_SEPARATOR . 'phpword' . DIRECTORY_SEPARATOR,
                    );
            },
            E_DEPRECATED,
        );
    }

    public static function restore(): void
    {
        restore_error_handler();
    }
}
