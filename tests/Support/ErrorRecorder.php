<?php

declare(strict_types=1);

namespace Spora\Plugins\Word\Tests\Support;

/**
 * Sits underneath whatever error handler a test installs, recording every
 * diagnostic that reaches it.
 *
 * `set_error_handler`'s stack — not `error_reporting` — is what
 * {@see \Spora\Plugins\Word\Services\WordConversion}'s suppressor manipulates,
 * so this recorder is the only place a diagnostic the suppressor *declines*
 * becomes observable. Counting PHPWord's own notices is how a test proves the
 * frame was popped again: if it were still installed, an unmediated render
 * afterwards would be swallowed here too.
 */
final class ErrorRecorder
{
    /** @var list<array{errno: int, message: string, file: string}> */
    private array $entries = [];

    public function __invoke(int $errno, string $errstr, string $errfile): bool
    {
        $this->entries[] = ['errno' => $errno, 'message' => $errstr, 'file' => $errfile];

        return true;
    }

    /**
     * @return list<array{errno: int, message: string, file: string}>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    /**
     * Diagnostics raised inside `vendor/phpoffice/phpword`. Non-zero proves
     * this recorder is *above* any suppressor under test.
     */
    public function phpwordNoticeCount(): int
    {
        return count(array_filter(
            $this->entries,
            static fn(array $entry): bool => str_contains($entry['file'], '/phpoffice/phpword/'),
        ));
    }
}
