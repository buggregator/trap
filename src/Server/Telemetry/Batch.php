<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Telemetry;

/**
 * Telemetry of one request to Trap, stored at once.
 *
 * @internal
 */
final class Batch
{
    /** @var list<Transaction> */
    public array $transactions = [];

    /** @var list<Span> */
    public array $spans = [];

    /** @var list<Log> */
    public array $logs = [];

    /** @var list<Metric> */
    public array $metrics = [];

    /**
     * @param non-empty-string $project
     */
    public function __construct(
        public readonly string $project,
    ) {}

    /**
     * @return list<'trace'|'log'|'metric'> What the batch holds, as the UI names it.
     */
    public function kinds(): array
    {
        $kinds = [];
        $this->transactions === [] && $this->spans === [] or $kinds[] = 'trace';
        $this->logs === [] or $kinds[] = 'log';
        $this->metrics === [] or $kinds[] = 'metric';

        return $kinds;
    }
}
