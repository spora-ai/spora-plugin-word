<?php

declare(strict_types=1);

namespace Spora\Plugins\Office\Exceptions;

use Throwable;

/**
 * A conversion refused to happen.
 *
 * Exists so callers never have to know the two upstream exception trees —
 * `MarkdownWord\Exception\Exception` and `MarkdownPresentation\Exception\Exception`
 * are each the package's documented single-catch base, and
 * {@see \Spora\Plugins\Office\Services\OfficeConversion} maps both here. The
 * upstream exception is always preserved as `$previous`, so a log line that
 * needs the original wording still has it.
 */
final class OfficeDocumentException extends OfficeRuntimeException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Wrap an upstream failure with the operation that provoked it. Keeping
     * the sentence here rather than at each call site means every mapped
     * failure reads the same way.
     */
    public static function fromUpstream(string $operation, Throwable $previous): self
    {
        return new self(
            sprintf('%s failed: %s', $operation, $previous->getMessage()),
            $previous,
        );
    }
}
