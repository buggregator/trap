<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Profiler\Compaction;

use Buggregator\Trap\Module\Profiler\Compaction\NodeBudget;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Covers(NodeBudget::class)]
#[Test]
final class NodeBudgetTest
{
    public function testDividesFreeMemoryLessHeadroom(): void
    {
        $limit = 512 << 20;
        $usage = 40 << 20;

        $nodes = NodeBudget::nodes('512M', $usage);

        $free = $limit - $usage - (int) ($limit * 0.1) - NodeBudget::RESERVE;
        Assert::same($nodes, \intdiv($free, NodeBudget::BYTES_PER_NODE));
    }

    public function testGivesMinimumWhenMemoryIsShort(): void
    {
        Assert::same(NodeBudget::nodes('128M', 120 << 20), NodeBudget::MIN_NODES);
    }

    public function testGivesMaximumWithoutLimit(): void
    {
        Assert::same(NodeBudget::nodes('-1', 1 << 30), NodeBudget::MAX_NODES);
        Assert::same(NodeBudget::nodes('64G', 0), NodeBudget::MAX_NODES);
    }
}
