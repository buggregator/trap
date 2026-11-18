<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Server\Sender;

use Buggregator\Trap\Server\Sender\HttpEndpoint;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Test;

#[Test]
#[Covers(HttpEndpoint::class)]
final class HttpEndpointTest
{
    #[DataSet(['127.0.0.1'], 'IPv4 loopback')]
    #[DataSet(['127.255.0.9'], 'other IPv4 loopback')]
    #[DataSet(['::1'], 'IPv6 loopback')]
    #[DataSet(['[::1]'], 'IPv6 loopback in brackets')]
    #[DataSet(['0:0:0:0:0:0:0:1'], 'IPv6 loopback in full')]
    #[DataSet(['0000::0001'], 'IPv6 loopback with zeros')]
    #[DataSet(['::ffff:127.0.0.1'], 'IPv4-mapped loopback')]
    #[DataSet(['[::FFFF:7f00:2]'], 'IPv4-mapped loopback in hex')]
    #[DataSet(['LocalHost'], 'localhost')]
    #[DataSet(['localhost.'], 'localhost with the root dot')]
    public function testLoopbackHost(string $host): void
    {
        Assert::true(HttpEndpoint::isLoopback($host));
    }

    #[DataSet(['0.0.0.0'], 'any IPv4')]
    #[DataSet(['::'], 'any IPv6')]
    #[DataSet(['192.168.1.10'], 'LAN address')]
    #[DataSet(['::2'], 'other IPv6')]
    #[DataSet(['::ffff:192.168.1.10'], 'IPv4-mapped LAN address')]
    #[DataSet(['::127.0.0.1'], 'IPv4-compatible address')]
    #[DataSet(['127.0.0.1.example'], 'host name of digits')]
    #[DataSet(['localhost.example'], 'localhost prefix')]
    #[DataSet(['trap.internal'], 'host name')]
    public function testHostBeyondLoopback(string $host): void
    {
        Assert::false(HttpEndpoint::isLoopback($host));
    }

    #[DataSet(['0.0.0.0', 'http://0.0.0.0:8000'], 'IPv4')]
    #[DataSet(['::', 'http://[::]:8000'], 'IPv6')]
    #[DataSet(['[::1]', 'http://[::1]:8000'], 'IPv6 in brackets')]
    #[DataSet(['trap.internal', 'http://trap.internal:8000'], 'host name')]
    public function testUrl(string $host, string $url): void
    {
        Assert::same(HttpEndpoint::url($host, 8000), $url);
    }
}
