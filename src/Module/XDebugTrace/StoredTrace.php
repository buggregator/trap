<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\XDebugTrace;

use Buggregator\Trap\Module\XDebugTrace\Trace\Summary;

/**
 * A trace in the {@see TraceStorage}.
 *
 * @psalm-import-type SummaryData from Summary
 *
 * @internal
 */
final class StoredTrace
{
    /**
     * @param non-empty-string $uuid Of the file and of its event.
     * @param non-empty-string $path
     * @param int<0, max> $size Bytes stored, as sent.
     * @param SummaryData $summary
     */
    public function __construct(
        public readonly string $uuid,
        public readonly string $path,
        public readonly int $size,
        public readonly bool $compressed,
        public readonly array $summary,
    ) {}
}
