<?php

declare(strict_types=1);

namespace Spora\Plugins\Word\Exceptions;

use InvalidArgumentException;

/**
 * The caller asked for something this plugin cannot accept: a derivative
 * format other than `docx`, or an unknown option value.
 *
 * Extends the SPL {@see InvalidArgumentException} so a caller that already
 * catches the SPL base keeps working, while the dedicated subclass keeps
 * "bad request" distinguishable from "the engine failed"
 * ({@see WordDocumentException}).
 */
final class WordInvalidArgumentException extends InvalidArgumentException {}
