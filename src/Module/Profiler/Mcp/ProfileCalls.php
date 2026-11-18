<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Profiler\Mcp;

use Buggregator\Trap\Module\Mcp\Tool;
use Buggregator\Trap\Module\Mcp\Tool\Arguments;
use Buggregator\Trap\Module\Profiler\Struct\Cost;
use Buggregator\Trap\Module\Profiler\Struct\Edge;
use Buggregator\Trap\Module\Profiler\Struct\Metric;
use Buggregator\Trap\Support\TimeSlice;

/**
 * The callers and the callees of a function: its neighbourhood in the call graph of a profile.
 *
 * @psalm-type Link = array{calls: int, inclusive: int<0, max>, exclusive: float}
 *
 * @internal
 */
final class ProfileCalls implements Tool
{
    private const LIMIT = 10;
    private const MAX_LIMIT = 50;
    private const MAX_DEPTH = 3;

    /** Rows of a list, all the levels together. */
    private const MAX_ROWS = 200;

    public function __construct(
        private readonly ProfileAnalyses $analyses,
    ) {}

    #[\Override]
    public function name(): string
    {
        return 'profile_calls';
    }

    #[\Override]
    public function title(): string
    {
        return 'Callers and callees of a function in a profile';
    }

    #[\Override]
    public function description(): string
    {
        return 'Shows who calls a function of a profile and what it calls, with the calls and the inclusive and '
            . 'exclusive values of the metric between them, heaviest first: walk the call graph from a function '
            . '`profile_summary` or `profile_top` pointed at, to the caller that makes it slow or the callee where '
            . 'the time goes. `depth` adds the callers of the callers and the callees of the callees.';
    }

    #[\Override]
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ProfileAnalyses::idSchema(),
                'function' => [
                    'type' => 'string',
                    'description' => 'The exact function name, as `profile_top` gives it.',
                ],
                'metric' => ProfileSchema::metric(),
                'depth' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => self::MAX_DEPTH,
                    'default' => 1,
                    'description' => 'Levels of callers and callees. Past the first level the values are of all '
                        . 'the calls between those two functions in the profile, not only of the ones on the way '
                        . 'to or from this function.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => self::MAX_LIMIT,
                    'default' => self::LIMIT,
                    'description' => 'The heaviest callers and callees to show of each function.',
                ],
            ],
            'required' => ['id', 'function'],
            'additionalProperties' => false,
        ];
    }

    #[\Override]
    public function outputSchema(): ?array
    {
        $links = [
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'properties' => [
                    'function' => ['type' => 'string'],
                    'via' => [
                        'type' => 'string',
                        'description' => 'The function of the level above this one calls, or is called by.',
                    ],
                    'depth' => ['type' => 'integer', 'description' => '1 for the direct callers and callees.'],
                    'calls' => ['type' => 'integer', 'description' => 'Calls between the two functions.'],
                    'inclusive' => ['type' => 'integer', 'description' => 'The metric of the callee in these calls.'],
                    'inclusive_percent' => ProfileSchema::percent(),
                    'exclusive' => [
                        'type' => 'integer',
                        'description' => 'The callee\'s own cost in these calls; split by the shares of its '
                            . 'inclusive cost when it is called from several places.',
                    ],
                    'exclusive_percent' => ProfileSchema::percent(),
                ],
                'required' => ['function', 'via', 'depth', 'calls', 'inclusive', 'inclusive_percent', 'exclusive', 'exclusive_percent'],
                'additionalProperties' => false,
            ],
        ];

        return [
            'type' => 'object',
            'properties' => [
                ...ProfileSchema::metricProperties(),
                'function' => ProfileSchema::row(),
                'root' => ['type' => 'boolean', 'description' => 'Some calls of the function have no caller.'],
                'callers' => [...$links, 'description' => 'Depth first: a caller of a caller follows its callee.'],
                'callees' => [...$links, 'description' => 'Depth first: a callee of a callee follows its caller.'],
                'callers_count' => ['type' => 'integer', 'description' => 'All the direct callers.'],
                'callees_count' => ['type' => 'integer', 'description' => 'All the direct callees.'],
                'truncated' => [
                    'type' => 'boolean',
                    'description' => \sprintf('A list reached %d rows and was cut.', self::MAX_ROWS),
                ],
            ],
            'required' => ['metric', 'unit', 'peak', 'function', 'root', 'callers', 'callees', 'callers_count', 'callees_count', 'truncated'],
            'additionalProperties' => false,
        ];
    }

    #[\Override]
    public function annotations(): array
    {
        return ['readOnlyHint' => true, 'openWorldHint' => false];
    }

    #[\Override]
    public function call(array $arguments): array
    {
        $analysis = $this->analyses->get($arguments);
        /** @var mixed $name */
        $name = $arguments['function'] ?? null;
        \is_string($name) && $name !== ''
            or throw new \InvalidArgumentException('`function` must be a non-empty string.');
        $func = $analysis->function($name);
        $metric = $analysis->metric($arguments);
        $depth = \max(1, Arguments::integer($arguments, 'depth', 1, self::MAX_DEPTH) ?? 1);
        $limit = \max(1, Arguments::integer($arguments, 'limit', 1, self::MAX_LIMIT) ?? self::LIMIT);

        $root = false;
        foreach ($analysis->ids($name) as $id) {
            foreach ($analysis->profile->calls->callers($id) as $edge) {
                $edge->callerId === null and $root = true;
            }
        }

        $callerLinks = self::links($analysis, $metric, $name, true);
        $calleeLinks = self::links($analysis, $metric, $name, false);
        $callers = $callees = [];
        $truncated = false;
        $callerCache = $calleeCache = [];
        self::walk($analysis, $metric, $name, $callerLinks, true, 1, $depth, $limit, [$name => true], $callers, $truncated, $callerCache);
        self::walk($analysis, $metric, $name, $calleeLinks, false, 1, $depth, $limit, [$name => true], $callees, $truncated, $calleeCache);

        return [
            'metric' => $metric->value,
            'unit' => $analysis->unit($metric),
            'peak' => $analysis->peak($metric),
            'function' => $analysis->row($func, $metric),
            'root' => $root,
            'callers' => $callers,
            'callees' => $callees,
            'callers_count' => \count($callerLinks),
            'callees_count' => \count($calleeLinks),
            'truncated' => $truncated,
        ];
    }

    /**
     * Adds the heaviest callers or callees of the function, each followed by its own up to the depth.
     *
     * @param array<string, Link> $links The callers or the callees of the function, as {@see self::links()} gives them.
     * @param int<1, max> $depth
     * @param int<1, max> $maxDepth
     * @param int<1, max> $limit
     * @param array<string, true> $path The functions on the way here, not expanded again.
     * @param list<array<non-empty-string, mixed>> $rows
     * @param array<string, array<string, Link>> $cache The links of the functions met in this direction, by their
     *        names: a function many rows reach is linked once.
     */
    private static function walk(
        ProfileAnalysis $analysis,
        Metric $metric,
        string $name,
        array $links,
        bool $callers,
        int $depth,
        int $maxDepth,
        int $limit,
        array $path,
        array &$rows,
        bool &$truncated,
        array &$cache,
    ): void {
        foreach (\array_slice($links, 0, $limit, true) as $other => $link) {
            if (\count($rows) >= self::MAX_ROWS) {
                $truncated = true;
                return;
            }

            $other = (string) $other;
            $exclusive = \max(0, Cost::saturate(\round($link['exclusive'])));
            $rows[] = [
                'function' => $other,
                'via' => $name,
                'depth' => $depth,
                'calls' => $link['calls'],
                'inclusive' => $link['inclusive'],
                'inclusive_percent' => $analysis->percent($metric, $link['inclusive']),
                'exclusive' => $exclusive,
                'exclusive_percent' => $analysis->percent($metric, $exclusive),
            ];
            $depth < $maxDepth && !isset($path[$other]) and self::walk(
                $analysis,
                $metric,
                $other,
                $cache[$other] ??= self::links($analysis, $metric, $other, $callers),
                $callers,
                $depth + 1,
                $maxDepth,
                $limit,
                $path + [$other => true],
                $rows,
                $truncated,
                $cache,
            );
        }
    }

    /**
     * The calls between the function and its callers or callees, summed over the recursion levels.
     *
     * @return array<string, Link> By the other function's names, heaviest first.
     */
    private static function links(ProfileAnalysis $analysis, Metric $metric, string $name, bool $callers): array
    {
        $graph = $analysis->profile->calls;
        $slice = $analysis->timeSlice();
        /** @var array<string, list<Edge>> $edges The calls of each pair, an edge per pair of recursion levels */
        $edges = [];
        foreach ($analysis->ids($name) as $id) {
            foreach ($callers ? $graph->callers($id) : $graph->callees($id) as $edge) {
                $other = $callers ? $edge->caller : $edge->callee;
                $other === null or $edges[$other][] = $edge;
                $slice->tick();
            }
        }

        $links = [];
        foreach ($edges as $other => $pair) {
            $nested = $metric === Metric::Count ? [] : self::nested($pair, $slice);
            $link = ['calls' => 0, 'inclusive' => 0, 'exclusive' => 0.0];
            foreach ($pair as $i => $edge) {
                $link['calls'] = Cost::saturate($link['calls'] + $edge->cost->ct);
                $link['exclusive'] += $analysis->exclusive($edge, $metric, $slice);
                isset($nested[$i]) or $link['inclusive'] = Cost::sum($link['inclusive'], $metric->of($edge->cost));
                $slice->tick();
            }
            $link['exclusive'] = \min($link['exclusive'], (float) $link['inclusive']);
            $links[$other] = $link;
        }

        // Not `uksort()`: a callback per comparison takes seconds for 200 000 callees, all in one tick
        $names = \array_map(\strval(...), \array_keys($links));
        $inclusive = \array_column($links, 'inclusive');
        $calls = \array_column($links, 'calls');
        \array_multisort($inclusive, \SORT_DESC, \SORT_NUMERIC, $calls, \SORT_DESC, \SORT_NUMERIC, $names, \SORT_STRING);
        $sorted = [];
        foreach ($names as $other) {
            $sorted[$other] = $links[$other];
            $slice->tick();
        }

        return $sorted;
    }

    /**
     * The calls that are a part of an outer call of the same pair, made deeper in both functions' recursions:
     * summed, a recursion would count many times over. A call deeper in one function only, as `g@1()` calling `f@1`
     * and `g()` calling `f@1` too, is apart from the others.
     *
     * @param list<Edge> $pair All the calls between the caller and the callee.
     *
     * @return array<int<0, max>, true> By the indexes in the pair.
     */
    private static function nested(array $pair, TimeSlice $slice): array
    {
        $callerLevels = $calleeLevels = [];
        foreach ($pair as $edge) {
            $callerLevels[] = self::level($edge->callerId, $edge->caller);
            $calleeLevels[] = self::level($edge->calleeId, $edge->callee);
        }
        $order = \array_keys($pair);
        $sorted = $callerLevels;
        \array_multisort($sorted, \SORT_ASC, \SORT_NUMERIC, $order);

        // An edge is nested when an edge of a smaller caller level has a smaller callee level
        $nested = [];
        $min = $levelMin = \PHP_INT_MAX;
        $level = null;
        foreach ($order as $i) {
            if ($callerLevels[$i] !== $level) {
                $min = \min($min, $levelMin);
                $levelMin = \PHP_INT_MAX;
                $level = $callerLevels[$i];
            }
            $min < $calleeLevels[$i] and $nested[$i] = true;
            $levelMin = \min($levelMin, $calleeLevels[$i]);
            $slice->tick();
        }

        return $nested;
    }

    /**
     * @return int<0, max> The recursion level of a function ID: `foo@2` is 2.
     */
    private static function level(?string $id, ?string $name): int
    {
        return $id === null || $id === $name ? 0 : \max(0, (int) \substr($id, \strlen((string) $name) + 1));
    }
}
