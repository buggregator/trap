<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Frontend;

use Buggregator\Trap\Module\Frontend\ConnectionPool;
use Buggregator\Trap\Module\Frontend\Publisher;
use Buggregator\Trap\Server\Traffic\StreamClient;
use Internal\Container\ObjectContainer;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Covers(Publisher::class)]
#[Covers(ConnectionPool::class)]
#[Test]
final class PublisherTest
{
    public function testSubscribesClientBeforeFirstPublication(): void
    {
        $container = new ObjectContainer();
        $sent = [];
        $client = \Mockery::mock(StreamClient::class);
        $client->allows('sendData')->andReturnUsing(static function (string $frame) use (&$sent): bool {
            $sent[] = \json_decode(\substr($frame, 2), true);
            return true;
        });
        $container->get(ConnectionPool::class)->addStream($client);
        $publisher = $container->get(Publisher::class);

        $publisher->publish('xdebug', 'session.created', ['id' => '1']);
        $publisher->publish('xdebug', 'session.closed', ['id' => '1']);

        Assert::same($sent, [
            ['push' => ['channel' => 'xdebug', 'subscribe' => []]],
            ['push' => ['channel' => 'xdebug', 'pub' => ['data' => ['event' => 'session.created', 'data' => ['id' => '1']]]]],
            ['push' => ['channel' => 'xdebug', 'pub' => ['data' => ['event' => 'session.closed', 'data' => ['id' => '1']]]]],
        ]);
        Assert::same($container->get(ConnectionPool::class)->channels(), ['xdebug']);
    }
}
