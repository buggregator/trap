<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Frontend;

use Buggregator\Trap\Module\Frontend\ConnectionPool;
use Buggregator\Trap\Module\Frontend\TelemetryPusher;
use Buggregator\Trap\Server\Traffic\StreamClient;
use Internal\Container\ObjectContainer;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Covers(TelemetryPusher::class)]
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
        $pool = (new ObjectContainer())->get(ConnectionPool::class);
        $pool->addStream($client);

        (new TelemetryPusher($pool))->stored('shop', ['trace', 'log']);

        Assert::string($sent)->endsWith(
            '{"push":{"channel":"events.project.shop","pub":{"data":{"event":"sentry.stored",'
            . '"data":{"project":"shop","kinds":["trace","log"]}}}}}',
        );
    }
}
