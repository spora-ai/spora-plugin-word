<?php

declare(strict_types=1);

namespace Spora\Plugins\Word\Exceptions;

use MarkdownWord\Exception\Exception as MarkdownWordException;
use Throwable;

/**
 * A conversion refused to happen.
 *
 * Exists so callers never have to know PHPWord's exception tree —
 * `MarkdownWord\Exception\Exception` is the package's documented single-catch
 * base and {@see \Spora\Plugins\Word\Services\WordConversion} maps it here.
 * The upstream exception is always preserved as `$previous`, so a log line
 * that needs the original wording still has it.
 */
final class WordDocumentException extends WordRuntimeException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Wrap a `MarkdownWord\Exception\Exception` with the operation that
     * provoked it. Keeping the sentence here rather than at each call site
     * means every mapped failure reads the same way.
     */
    public static function fromMarkdownWord(string $operation, MarkdownWordException $previous): self
    {
        return new self(
            sprintf('%s failed: %s', $operation, $previous->getMessage()),
            $previous,
        );
    }
}
