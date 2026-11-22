<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\XDebug\Link;

use Buggregator\Trap\Module\XDebug\Link\StreamLink;
use Buggregator\Trap\Test\Mock\StreamClientMock;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Test;

#[Covers(StreamLink::class)]
#[Test]
final class StreamLinkTest
{
    #[DataSet(['127.0.0.1', true])]
    #[DataSet(['127.1.2.3', true])]
    #[DataSet(['::1', true])]
    #[DataSet(['::ffff:127.0.0.1', true])]
    #[DataSet(['192.168.1.2', false])]
    #[DataSet(['::ffff:192.168.1.2', false])]
    #[DataSet(['fe80::1', false])]
    #[DataSet([null, false])]
    public function testTellsLoopbackAddress(?string $address, bool $loopback): void
    {
        Assert::same(StreamLink::isLoopbackAddress($address), $loopback);
    }

    public function testStreamWithoutPeerAddressIsNotLoopback(): void
    {
        Assert::false(StreamLink::of(StreamClientMock::createFromGenerator((static fn() => yield '')()))->isLoopback());
    }
}
