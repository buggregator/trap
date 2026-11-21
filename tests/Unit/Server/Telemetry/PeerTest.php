<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Server\Telemetry;

use Buggregator\Trap\Server\Telemetry\Peer;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Test;

#[Covers(Peer::class)]
#[Test]
final class PeerTest
{
    #[DataSet(['db.sql.query', ['db.name' => 'shop'], '', 'db', 'shop'])]
    #[DataSet(['db', ['server.address' => 'pg', 'db.name' => 'shop'], '', 'db', 'pg'])]
    #[DataSet(['http.client', ['http.url' => 'https://api.test:8443/pay?x=1'], '', 'http', 'api.test:8443'])]
    #[DataSet(['http.client', ['server.address' => 'billing', 'server.port' => 8080], '', 'http', 'billing:8080'])]
    #[DataSet(['http.client', [], 'POST http://billing/orders', 'http', 'billing'])]
    #[DataSet(['http.client', [], 'POST /orders', 'http', null])]
    #[DataSet(['cache.get', ['server.address' => 'redis'], '', 'cache', 'redis'])]
    #[DataSet(['queue.publish', ['messaging.destination.name' => 'orders'], '', 'queue', 'orders'])]
    #[DataSet(['dbx', ['db.name' => 'shop'], '', 'unknown', null])]
    #[DataSet(['http.server', ['http.url' => 'http://a'], '', 'unknown', null])]
    public function testClassifiesByOpAndAttributes(
        string $op,
        array $attributes,
        string $description,
        string $type,
        ?string $address,
    ): void {
        $peer = Peer::classify($op, $attributes, $description);

        Assert::same($peer->type, $type);
        Assert::same($peer->address, $address);
    }
}
