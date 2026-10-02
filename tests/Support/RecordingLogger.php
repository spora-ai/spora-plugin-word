<?php

declare(strict_types=1);

namespace Spora\Plugins\Word\Tests\Support;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * A PSR-3 logger that keeps what it was told, so a test can prove a
 * best-effort path *ran* instead of silently doing nothing.
 *
 * `MediaArchiveIngestPipeline::runConversionPipeline()` swallows a converter
 * throw and records the reason at `warning`. Without a logger there, a
 * corrupt attachment and an unclaimed MIME are indistinguishable from the
 * outside — both surface as a NULL `markdown_content`. The record is the
 * only evidence that the converter was tried and gave up.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    private array $records = [];

    /**
     * @param  array<string, mixed> $context
     */
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level'   => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    /**
     * @return list<array{level: string, message: string, context: array<string, mixed>}>
     */
    public function records(): array
    {
        return $this->records;
    }

    /**
     * @return list<array{level: string, message: string, context: array<string, mixed>}>
     */
    public function warnings(): array
    {
        return array_values(array_filter(
            $this->records,
            static fn(array $record): bool => $record['level'] === 'warning',
        ));
    }
}
