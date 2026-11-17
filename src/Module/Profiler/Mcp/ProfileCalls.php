<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Profiler\Mcp;

use Buggregator\Trap\Module\Mcp\Tool;
use Buggregator\Trap\Module\Mcp\Tool\Arguments;
use Buggregator\Trap\Module\Profiler\Struct\Metric;

/**
 * The callers and the callees of a function: its neighbourhood in the call graph of a profile.
 *
 * @psalm-type Link = array{calls: int, inclusive: int, exclusive: float}
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
                    'inclusive_percent' => ['type' => 'number'],
                    'exclusive' => [
                        'type' => 'integer',
                        'description' => 'The callee\'s own cost in these calls; split by the shares of its '
                            . 'inclusive cost when it is called from several places.',
                    ],
                    'exclusive_percent' => ['type' => 'number'],
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

        $callers = $callees = [];
        $truncated = false;
        self::walk($analysis, $metric, $name, true, 1, $depth, $limit, [$name => true], $callers, $truncated);
        self::walk($analysis, $metric, $name, false, 1, $depth, $limit, [$name => true], $callees, $truncated);

        return [
            'metric' => $metric->value,
            'unit' => $analysis->unit($metric),
            'peak' => $analysis->peak($metric),
            'function' => $analysis->row($func, $metric),
            'root' => $root,
            'callers' => $callers,
            'callees' => $callees,
            'callers_count' => \count(self::links($analysis, $metric, $name, true)),
            'callees_count' => \count(self::links($analysis, $metric, $name, false)),
            'truncated' => $truncated,
        ];
    }

    /**
     * Adds the heaviest callers or callees of the function, each followed by its own up to the depth.
     *
     * @param int<1, max> $depth
     * @param int<1, max> $maxDepth
     * @param int<1, max> $limit
     * @param array<string, true> $path The functions on the way here, not expanded again.
     * @param list<array<non-empty-string, mixed>> $rows
     */
    private static function walk(
        ProfileAnalysis $analysis,
        Metric $metric,
        string $name,
        bool $callers,
        int $depth,
        int $maxDepth,
        int $limit,
        array $path,
        array &$rows,
        bool &$truncated,
    ): void {
        foreach (\array_slice(self::links($analysis, $metric, $name, $callers), 0, $limit, true) as $other => $link) {
            if (\count($rows) >= self::MAX_ROWS) {
                $truncated = true;
                return;
            }

            $other = (string) $other;
            $exclusive = (int) \round($link['exclusive']);
            $rows[] = [
                'function' => $other,
                'via' => $name,
                'depth' => $depth,
                'calls' => $link['calls'],
                'inclusive' => $link['inclusive'],
                'inclusive_percent' => $analysis->percent($metric, \max(0, $link['inclusive'])),
                'exclusive' => $exclusive,
                'exclusive_percent' => $analysis->percent($metric, \max(0, $exclusive)),
            ];
            $depth < $maxDepth && !isset($path[$other]) and self::walk(
                $analysis,
                $metric,
                $other,
                $callers,
                $depth + 1,
                $maxDepth,
                $limit,
                $path + [$other => true],
                $rows,
                $truncated,
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
        $links = [];
        /** @var array<string, int> $nested The heaviest call of each pair nested in another call of the callee */
        $nested = [];
        foreach ($analysis->ids($name) as $id) {
            foreach ($callers ? $graph->callers($id) : $graph->callees($id) as $edge) {
                $other = $callers ? $edge->caller : $edge->callee;
                if ($other === null) {
                    continue;
                }

                $link = $links[$other] ?? ['calls' => 0, 'inclusive' => 0, 'exclusive' => 0.0];
                $value = $metric->of($edge->cost);
                $link['calls'] += $edge->cost->ct;
                // A nested call is a part of the outer one: summed, a recursion would count many times over
                $edge->isRecursive() && $metric !== Metric::Count
                    ? $nested[$other] = \max($nested[$other] ?? 0, $value)
                    : $link['inclusive'] += $value;
                $link['exclusive'] += $analysis->exclusive($edge, $metric);
                $links[$other] = $link;
            }
        }

        // Only nested calls, as of a function calling itself: the outermost ones include the rest
        foreach ($nested as $other => $value) {
            $links[$other]['inclusive'] === 0 and $links[$other]['inclusive'] = $value;
        }

        \uksort(
            $links,
            static fn(int|string $a, int|string $b): int => [$links[$b]['inclusive'], $links[$b]['calls'], (string) $a]
                <=> [$links[$a]['inclusive'], $links[$a]['calls'], (string) $b],
        );

        return $links;
    }
}
