<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Frontend;

use Buggregator\Trap\Module\Frontend\ConnectionPool;
use Buggregator\Trap\Module\Frontend\Publisher;
use Buggregator\Trap\Module\Frontend\TelemetryPusher;
use Buggregator\Trap\Server\Traffic\StreamClient;
use Buggregator\Trap\Server\Traffic\Websocket\Frame;
use Internal\Container\ObjectContainer;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Covers(TelemetryPusher::class)]
#[Covers(Publisher::class)]
#[Test]
final class TelemetryPusherTest
{
    public function testPushesStoredKindsOnTheProjectChannel(): void
    {
        $sent = '';
        $client = \Mockery::mock(StreamClient::class);
        $client->shouldReceive('sendData')->andReturnUsing(static function (string $data) use (&$sent): bool {
            $sent .= $data;
            return true;
        });
        // A client gets publications once connected
        $client->allows('getIterator')->andReturnUsing(static function (): \Generator {
            yield (string) Frame::text('{"id":1,"connect":{}}');
            while (true) {
                \Fiber::suspend();
            }
        });
        $pool = (new ObjectContainer())->get(ConnectionPool::class);
        $pool->addStream($client);
        $pool->process();

        (new TelemetryPusher(new Publisher($pool)))->stored('shop', ['trace', 'log']);

        Assert::string($sent)->endsWith(
            '{"push":{"channel":"events.project.shop","pub":{"data":{"event":"sentry.stored",'
            . '"data":{"project":"shop","kinds":["trace","log"]}}}}}',
        );
    }
}
