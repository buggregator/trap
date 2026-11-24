<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Telemetry\Insight;

use Buggregator\Trap\Server\Telemetry\Span;
use Buggregator\Trap\Server\Telemetry\Trace;
use Buggregator\Trap\Server\Telemetry\Transaction;

/**
 * Runs the {@see TraceRule}s over a trace when a reader asks: the trace page of the web UI and the trace tools
 * of the MCP server share the findings.
 *
 * A container singleton. The findings stay on the {@see Trace} with the state they were found at and serve every
 * read until the trace changes: its {@see Trace::$seq} grows with every arrival, and its size drops when spans go
 * by eviction or clearing, which leave the sequence number alone.
 *
 * @internal
 */
final class TraceInsights
{
    /** Project sets whose findings a trace keeps at once */
    private const MAX_SCOPES = 16;

    /** @var list<TraceRule> */
    private readonly array $rules;

    /**
     * @param list<TraceRule>|null $rules The {@see self::defaultRules()} when null.
     */
    public function __construct(?array $rules = null)
    {
        $this->rules = $rules ?? self::defaultRules();
    }

    /**
     * @return list<TraceRule>
     */
    public static function defaultRules(): array
    {
        return [
            new RepeatedQuery(),
        ];
    }

    /**
     * @param list<string> $projects Only the part of the trace sent to these projects, as if the rest had not come:
     *        the spans of other projects neither count nor show; all of the trace when empty.
     * @return list<Finding> The heaviest first: by total duration, then by span count.
     */
    public function of(Trace $trace, array $projects = []): array
    {
        $projects = \array_values(\array_unique($projects));
        \sort($projects);
        $scope = \implode("\0", $projects);
        $state = [$this, $trace->seq, $trace->size()];
        $cached = $trace->insights[$scope] ?? null;
        if ($cached !== null && \array_slice($cached, 0, 3) === $state) {
            return $cached[3];
        }

        $findings = $this->find($projects === [] ? $trace : self::part($trace, $projects));

        // The findings of an older state of the trace are of no use any more
        $trace->insights = \array_filter(
            $trace->insights,
            static fn(array $entry): bool => \array_slice($entry, 0, 3) === $state,
        );
        \count($trace->insights) < self::MAX_SCOPES or \array_shift($trace->insights);
        $trace->insights[$scope] = [...$state, $findings];

        return $findings;
    }

    /**
     * @param non-empty-list<string> $projects
     */
    private static function part(Trace $trace, array $projects): Trace
    {
        $in = static fn(Span|Transaction $item): bool => \in_array($item->project, $projects, true);
        $part = new Trace($trace->id);
        $part->transactions = \array_filter($trace->transactions, $in);
        $part->spans = \array_filter($trace->spans, $in);
        $part->seq = $trace->seq;
        $part->updatedAt = $trace->updatedAt;

        return $part;
    }

    /**
     * @return list<Finding>
     */
    private function find(Trace $trace): array
    {
        $findings = [];
        foreach ($this->rules as $rule) {
            foreach ($rule->check($trace) as $finding) {
                $findings[] = $finding;
            }
        }

        // Stable: equal findings keep the order of the rules
        \usort($findings, static fn(Finding $a, Finding $b): int => [$b->totalMs, $b->count] <=> [$a->totalMs, $a->count]);

        return $findings;
    }
}
