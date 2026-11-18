<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Mcp;

use Buggregator\Trap\Module\Mcp\McpStatus;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(McpStatus::class)]
final class McpStatusTest
{
    public function testOffUntilTurnedOn(): void
    {
        $status = new McpStatus();
        $before = $status->isOn();

        $status->turnOn();

        Assert::false($before);
        Assert::true($status->isOn());
    }

    public function testNoToolCalledBeforeTheFirstCall(): void
    {
        Assert::false((new McpStatus())->toolCalledWithin(3600.0));
    }

    public function testToolCallIsRecentWithinTheWindow(): void
    {
        $status = new McpStatus();

        $status->toolCalled();

        Assert::true($status->toolCalledWithin(60.0));
    }

    public function testToolCallOlderThanTheWindowIsNotRecent(): void
    {
        $status = new McpStatus();
        $status->toolCalled();

        \usleep(1_000);

        Assert::false($status->toolCalledWithin(0.0001));
    }
}
