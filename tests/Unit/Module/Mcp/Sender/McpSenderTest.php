<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Mcp\Sender;

use Buggregator\Trap\Module\Mcp\McpStatus;
use Buggregator\Trap\Module\Mcp\Sender\McpSender;
use Buggregator\Trap\Server\Application\Module\Modules;
use Buggregator\Trap\Server\Event\Event;
use Buggregator\Trap\Server\Event\EventStore;
use Buggregator\Trap\Server\Proto\Frame\Binary;
use Internal\Container\ObjectContainer;
use Nyholm\Psr7\Stream;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(McpSender::class)]
final class McpSenderTest
{
    public function testStoreKeepsFramesForTheTools(): void
    {
        $container = self::container();
        $container->get(McpSender::class);
        $frame = new Binary(Stream::create('data'));

        $record = $container->get(EventStore::class)->add(new Event('a', 'binary', [], 1.0), $frame);

        Assert::same($record->frames, [$frame]);
    }

    public function testTurnsOnMcpStatus(): void
    {
        $container = self::container();
        $status = $container->get(McpStatus::class);
        $before = $status->isOn();

        $container->get(McpSender::class);

        Assert::false($before);
        Assert::true($status->isOn());
    }

    public function testHostIsUnsetByDefault(): void
    {
        $endpoint = self::container()->get(McpSender::class)->httpEndpoint();

        Assert::null($endpoint->host);
        Assert::same($endpoint->port, 8000);
    }

    private static function container(): ObjectContainer
    {
        $container = new ObjectContainer();
        $container->set(new Modules($container));

        return $container;
    }
}
