<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Monolog\Frontend;

use Buggregator\Trap\Module\Monolog\Frame as MonologFrame;
use Buggregator\Trap\Module\Monolog\Frontend\Mapper;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Covers(Mapper::class)]
#[Test]
final class MapperTest
{
    public function testFacetsAreChannelLevelHostnameAndTraceId(): void
    {
        $event = (new Mapper())->map(new MonologFrame([
            'message' => 'Payment failed',
            'channel' => 'payments',
            'level_name' => 'WARNING',
            'context' => ['trace_id' => 'from-context'],
            'extra' => ['hostname' => 'web-1', 'trace_id' => '4bf92f3577b34da6a3ce929d0e0e4736'],
        ]));

        Assert::same($event->facets, [
            'channel' => ['payments'],
            'level' => ['warning'],
            'host' => ['web-1'],
            'trace' => ['4bf92f3577b34da6a3ce929d0e0e4736'],
        ]);
    }

    public function testTraceIdOfContextWhenExtraHasNone(): void
    {
        $event = (new Mapper())->map(new MonologFrame(['message' => 'x', 'context' => ['trace_id' => 'abc'], 'extra' => []]));

        Assert::same($event->facets, ['trace' => ['abc']]);
    }
}
