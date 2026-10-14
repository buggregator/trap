<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Traffic\Proto\Server\Version;

use Buggregator\Trap\Proto\Server\Version\V1;
use Testo\Assert;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
final class V1Test
{
    public static function payloadsDataProvider(): iterable
    {
        yield ['1|0.1|9a06581c-3bec-4cf1-8412-b2a40bb36a76|[{"hello":"world"},{}]', true];
        yield ['1|0.1|9a06581c-3bec-4cf1-8412-b2a40bb36a76|[]', true];
        yield ['1|0.1|9a06581c-3bec-4cf1-8412-b2a40bb36a76|', false];
        yield ['1|0.1|9a06581c-3bec-4cf1-8412-b2a40bb36a76', false];
        yield ['abc', false];
    }

    #[DataProvider('payloadsDataProvider')]
    public function testIsSuppoerted(string $payload, bool $isSupported): void
    {
        $decoder = new V1();

        Assert::same($decoder->isSupport($payload), $isSupported);
    }

    public function testDecode(): void
    {
        $decoder = new V1();

        $request = $decoder->decode('1|0.1|9a06581c-3bec-4cf1-8412-b2a40bb36a76|[{},{}]');

        Assert::same($request->protocol, 1);
        Assert::same($request->client, '0.1');
        Assert::same($request->uuid, '9a06581c-3bec-4cf1-8412-b2a40bb36a76');
        Assert::same($request->payload, '[{},{}]');
    }
}
