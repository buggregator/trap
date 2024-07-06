<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Profiler\Struct;

use Buggregator\Trap\Module\Profiler\Struct\Branch;
use Buggregator\Trap\Module\Profiler\Struct\Cost;
use Buggregator\Trap\Module\Profiler\Struct\Edge;
use Buggregator\Trap\Module\Profiler\Struct\Tree;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TreeTest extends TestCase
{
    public static function topNumbers(): iterable
    {
        yield [1];
        yield [5];
        yield [10];
        yield [100];
        yield [1000];
    }

    #[DataProvider('topNumbers')]
    public function testTop(int $top): void
    {
        $edges = $this->generateEdges();
        $tree = Tree::fromEdgesList(
            $edges,
            static fn(Edge $edge) => $edge->callee,
            static fn(Edge $edge) => $edge->caller,
        );

        $result = $tree->top($top, static fn(Branch $a,Branch $b) => $b->item->cost->wt <=> $a->item->cost->wt);

        self::assertCount(\min($top, \count($edges)), $result);

        $topUnoptimized = \array_map(static fn (Edge $e) => $e->cost->wt, $edges);
        \rsort($topUnoptimized);
        $topUnoptimized = \array_slice($topUnoptimized, 0, $top);
        $topVals = \array_map(static fn (Branch $b) => $b->item->cost->wt, $result);
        self::assertSame($topUnoptimized, $topVals);
    }

    /**
     * @return array<Edge>
     */
    private function generateEdges(int $multiplier = 20): array
    {
        $result = [];

        for ($i = 0; $i < $multiplier; $i++) {
            for ($j = 0; $j <= $i; $j++) {
                $result[] = new Edge(
                    $i === 0 ? null : 'item-' . ($i - 1) . "-$j", "item-$i-$j",
                    new Cost(
                        ct: (int) $i**$i,
                        wt: ($multiplier - $i) * 1000 + $j,
                        cpu: ($multiplier - $i) * 10,
                        mu: (int) $i * 1000,
                        pmu: (int) $i * 1000,
                    ),
                );
            }
        }

        return $result;
    }
}
