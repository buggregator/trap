<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Telemetry\Insight;

use Buggregator\Trap\Server\Telemetry\Trace;

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
     * @param list<string> $projects Only the findings about the spans of these projects; all when empty.
     * @return list<Finding> The heaviest first: by total duration, then by span count.
     */
    public function of(Trace $trace, array $projects = []): array
    {
        $findings = $this->findings($trace);
        if ($projects === []) {
            return $findings;
        }

        return \array_values(\array_filter(
            $findings,
            static fn(Finding $finding): bool => \in_array(
                $trace->spans[$finding->spanIds[0]]->project ?? null,
                $projects,
                true,
            ),
        ));
    }

    /**
     * @return list<Finding>
     */
    private function findings(Trace $trace): array
    {
        $state = [$this, $trace->seq, $trace->size()];
        if ($trace->insights !== null && \array_slice($trace->insights, 0, 3) === $state) {
            return $trace->insights[3];
        }

        $findings = [];
        foreach ($this->rules as $rule) {
            foreach ($rule->check($trace) as $finding) {
                $findings[] = $finding;
            }
        }

        // Stable: equal findings keep the order of the rules
        \usort($findings, static fn(Finding $a, Finding $b): int => [$b->totalMs, $b->count] <=> [$a->totalMs, $a->count]);
        $trace->insights = [...$state, $findings];

        return $findings;
    }
}
