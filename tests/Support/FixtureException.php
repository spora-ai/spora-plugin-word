<?php

declare(strict_types=1);

namespace Spora\Plugins\Word\Tests\Support;

use RuntimeException;

/**
 * The one failure mode the fixture layer has of its own: the host refused to
 * put bytes on disk.
 *
 * Every throw site is that same story told at a different step — `tempnam()`
 * returning false, a `mkdir()` that did not take, a write that came back
 * `false`, a zip `ZipArchive` refused to create. Throwing a bare
 * `RuntimeException` there makes the suite's own breakage indistinguishable
 * from the exception the code under test throws, which is the difference
 * between a fixture that reports itself and a test that fails somewhere else
 * entirely. A dedicated type keeps "the environment said no" separable from
 * "the plugin is broken" in a test run.
 */
final class FixtureException extends RuntimeException {}
