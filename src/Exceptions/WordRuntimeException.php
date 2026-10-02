<?php

declare(strict_types=1);

namespace Spora\Plugins\Word\Exceptions;

use RuntimeException;

/**
 * Base for every failure this plugin reports, so a caller can catch the
 * plugin's own problems without also catching vendor exceptions.
 *
 * {@see WordDocumentException} narrows this to a failed conversion and
 * {@see WordInvalidArgumentException} deliberately does *not* extend it —
 * "you sent something this producer cannot accept" is not the engine
 * failing, and the split keeps that distinction catchable without
 * sniffing message strings.
 */
class WordRuntimeException extends RuntimeException {}
