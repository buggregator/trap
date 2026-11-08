<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Profiler\XDebug;

use Buggregator\Trap\Module\Profiler\Struct\Branch;
use Buggregator\Trap\Module\Profiler\Struct\Cost;
use Buggregator\Trap\Module\Profiler\Struct\Edge;
use Buggregator\Trap\Module\Profiler\Struct\Profile;
use Buggregator\Trap\Module\Profiler\Struct\Tree;

/**
 * Builds a profile from Xdebug profiler output in the cachegrind format.
 *
 * Xdebug writes a block per call when the call ends, and the block lists the calls made from it in their order.
 * So the blocks come in post-order, and the exact call tree is restored from them.
 *
 * @link https://xdebug.org/docs/profiler
 * @link https://valgrind.org/docs/manual/cl-format.html
 *
 * @psalm-import-type Metadata from Profile
 *
 * @internal
 */
final class ProfileBuilder
{
    /**
     * @param string $content Cachegrind data, gzipped or not.
     * @param Metadata $metadata
     * @param array<non-empty-string, non-empty-string> $tags
     *
     * @throws \InvalidArgumentException When the content is not a profile written by Xdebug.
     */
    public function createProfile(
        string $content,
        \DateTimeInterface $date = new \DateTimeImmutable(),
        array $metadata = [],
        array $tags = [],
    ): Profile {
        if (\str_starts_with($content, "\x1f\x8b")) {
            $content = @\gzdecode($content);
            $content === false and throw new \InvalidArgumentException('Invalid gzip data.');
        }

        return Profile::fromTree($this->buildTree(...$this->parse($content)), $date, $metadata, $tags);
    }

    /**
     * @return array{list<Call>, float} Calls in the order they end, and the divisor of time to microseconds.
     */
    private function parse(string $content): array
    {
        $lines = \preg_split('/\r?\n/', $content) ?: [];
        \str_starts_with($lines[0] ?? '', 'version:') && \str_contains($content, "\ncreator: xdebug")
            or throw new \InvalidArgumentException('Not a cachegrind profile written by Xdebug.');

        /** @var array<string, non-empty-string> $names Function names by their compressed IDs */
        $names = [];
        $timeDivisor = null;
        /** @var list<Call> $calls */
        $calls = [];
        $current = null;
        /** @var non-empty-string|null $callee Function of the next cost line, if it describes a call */
        $callee = null;
        $expectCost = false;

        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }

            if (\str_starts_with($line, 'events:')) {
                $timeDivisor = self::timeDivisor($line);
                continue;
            }

            if (\str_starts_with($line, 'fn=')) {
                $current === null or $calls[] = $current;
                $current = new Call(self::name(\substr($line, 3), $names));
                $expectCost = true;
                $callee = null;
                continue;
            }

            if (\str_starts_with($line, 'cfn=')) {
                $callee = self::name(\substr($line, 4), $names);
                continue;
            }

            if (\str_starts_with($line, 'calls=')) {
                $expectCost = true;
                continue;
            }

            if ($expectCost && $current !== null && \preg_match('/^[+-]?\d+ (-?\d+) (-?\d+)/', $line, $m) === 1) {
                $expectCost = false;
                if ($callee === null) {
                    $current->time = (int) $m[1];
                    $current->memory = (int) $m[2];
                } else {
                    $current->callees[] = $callee;
                    $callee = null;
                }
            }
        }
        $current === null or $calls[] = $current;

        $timeDivisor === null and throw new \InvalidArgumentException('No `events` line in the profile.');

        return [$calls, $timeDivisor];
    }

    /**
     * Links the calls to their parents: the calls made from a call are the last ones ended before it.
     *
     * @param list<Call> $calls In the order they end.
     *
     * @return Tree<Edge>
     */
    private function buildTree(array $calls, float $timeDivisor): Tree
    {
        /** @var list<Call> $ended Calls waiting for their caller */
        $ended = [];
        foreach ($calls as $call) {
            $count = \count($call->callees);
            $count > \count($ended) and throw new \InvalidArgumentException(
                "Call of `{$call->name}` lists more calls than ended before it.",
            );

            $call->children = $count === 0 ? [] : \array_splice($ended, -$count);
            foreach ($call->children as $i => $child) {
                $child->name === $call->callees[$i] or throw new \InvalidArgumentException(
                    "Call of `{$call->name}` lists `{$call->callees[$i]}`, but `{$child->name}` ended there.",
                );
                $call->time += $child->time;
                $call->memory += $child->memory;
            }
            $ended[] = $call;
        }

        /** @var Tree<Edge> $tree */
        $tree = new Tree();

        // Iterative: the depth follows the recursion in the profiled code, deeper than Xdebug's nesting limit
        /** @var list<array{Call, Branch<Edge>|null}> $stack */
        $stack = \array_map(static fn(Call $call): array => [$call, null], \array_reverse($ended));
        while ($stack !== []) {
            [$call, $parent] = \array_pop($stack);

            $id = 'n' . (\count($tree) + 1);
            $tree->addItem(
                new Edge(
                    caller: $parent?->item->callee,
                    callee: $call->name,
                    cost: new Cost(
                        ct: 1,
                        wt: \max(0, (int) \round((float) $call->time / $timeDivisor)),
                        cpu: 0,
                        mu: \max(0, $call->memory),
                        pmu: 0,
                    ),
                ),
                $id,
                $parent?->id,
            );

            /** @var Branch<Edge> $branch */
            $branch = $tree->all[$id];
            foreach (\array_reverse($call->children) as $child) {
                $stack[] = [$child, $branch];
            }
        }

        return $tree;
    }

    /**
     * Resolves a name compressed as `(id) name` on the first use and `(id)` afterward.
     *
     * @param array<string, non-empty-string> $names
     *
     * @return non-empty-string
     */
    private static function name(string $value, array &$names): string
    {
        if (\preg_match('/^\((\d+)\)(?: (.+))?$/', $value, $m) !== 1) {
            $value === '' and throw new \InvalidArgumentException('Empty function name.');
            return $value;
        }

        if (isset($m[2]) && $m[2] !== '') {
            return $names[$m[1]] = $m[2];
        }

        return $names[$m[1]] ?? throw new \InvalidArgumentException("Unknown function name ID `{$m[1]}`.");
    }

    /**
     * @return float Divisor of the time event to microseconds.
     */
    private static function timeDivisor(string $line): float
    {
        $event = \explode(' ', \trim(\substr($line, 7)))[0];

        return match ($event) {
            'Time_(10ns)' => 100.0,
            'Time_(ns)' => 1000.0,
            'Time', 'Time_(µs)' => 1.0,
            default => throw new \InvalidArgumentException("Unsupported time event `$event`."),
        };
    }
}
