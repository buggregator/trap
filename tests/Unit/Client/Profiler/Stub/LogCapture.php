<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Client\Profiler\Stub;

use Psr\Log\AbstractLogger;

/**
 * Keeps the log records in memory.
 */
final class LogCapture extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array}> */
    public array $records = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }

    /**
     * The messages, one per line.
     */
    public function messages(): string
    {
        return \implode("\n", \array_column($this->records, 'message'));
    }
}
