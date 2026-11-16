<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Server\Config;

use Buggregator\Trap\Server\Application\Config\Internal\ConfigInflector;
use Buggregator\Trap\Server\Config\Events;
use Internal\Container\ObjectContainer;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(Events::class)]
final class EventsTest
{
    public function testMaxEventsDefaultsTo200(): void
    {
        Assert::same(self::inflect('<trap/>')->maxEvents, 200);
    }

    public function testMaxEventsIsReadFromEventsElement(): void
    {
        Assert::same(self::inflect('<trap><events maxEvents="50"/></trap>')->maxEvents, 50);
    }

    private static function inflect(string $xml): Events
    {
        $config = new Events();
        (new ConfigInflector(xml: $xml))->inflect($config, new ObjectContainer());

        return $config;
    }
}
