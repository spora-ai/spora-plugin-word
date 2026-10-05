<?php

declare(strict_types=1);

namespace Spora\Plugins\Word\Tests\Support;

/**
 * Measures how many `set_error_handler()` frames a call left behind.
 *
 * `set_error_handler()` / `restore_error_handler()` share one LIFO stack and
 * PHP exposes no way to ask how deep it is, so depth is read the only way it
 * can be: by *displacing* the head handler. `set_error_handler()` returns the
 * callable it replaced, so pushing a throwaway recorder and popping it again
 * is a non-destructive peek at the top of the stack — no diagnostic is
 * provoked, which matters because the frames being hunted for swallow the
 * diagnostics a probe would otherwise raise.
 *
 * Identity comparison (`===`) is the right test rather than equality: a
 * `MediaDerivativeService` walk or a leaked suppressor both answer with a
 * different instance, and only the sentinel we installed ourselves is the
 * frame the caller expects to still be on top.
 */
final class ErrorHandlerStack
{
    /**
     * Walk-depth ceiling. A leaked frame is a real bug worth failing on; a
     * stack deeper than this means the harness itself is unbalanced, and
     * reporting the ceiling is more useful than popping the caller's frames
     * off the end of the stack.
     */
    private const MAX_FRAMES = 8;

    /**
     * How many handler frames the code under test installed **above** the
     * caller's `$sentinel` and failed to pop. Zero means the stack is
     * balanced.
     *
     * The walk pops only frames it did not push, so the caller's own
     * `restore_error_handler()` still balances the sentinel.
     */
    public static function leakedFramesAbove(ErrorRecorder $sentinel): int
    {
        for ($frames = 0; $frames < self::MAX_FRAMES; $frames++) {
            if (self::peek() === $sentinel) {
                return $frames;
            }

            restore_error_handler();
        }

        return self::MAX_FRAMES;
    }

    /**
     * The handler currently installed, read without leaving a frame behind.
     *
     * @return callable|null null when the stack was empty before the probe
     */
    private static function peek(): ?callable
    {
        $displaced = set_error_handler(new ErrorRecorder(), E_ALL);
        restore_error_handler();

        return $displaced;
    }
}
