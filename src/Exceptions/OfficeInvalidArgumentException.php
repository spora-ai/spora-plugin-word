<?php

declare(strict_types=1);

namespace Spora\Plugins\Office\Exceptions;

use InvalidArgumentException;

/**
 * The caller asked for a derivative format this plugin cannot produce.
 *
 * Extends the SPL {@see InvalidArgumentException} so a caller that already
 * catches the SPL base keeps working, while the dedicated subclass keeps
 * "bad request" distinguishable from "the engine failed"
 * ({@see OfficeDocumentException}).
 */
final class OfficeInvalidArgumentException extends InvalidArgumentException {}
