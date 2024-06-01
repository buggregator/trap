<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Traffic\Proto\Server\Version;

use Buggregator\Trap\Proto\Server\Version\V1;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class V1Test extends TestCase
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

        self::assertSame($isSupported, $decoder->isSupport($payload));
    }

    public function testDecode(): void
    {
        $decoder = new V1();

        $request = $decoder->decode('1|0.1|9a06581c-3bec-4cf1-8412-b2a40bb36a76|[{},{}]');

        self::assertSame(1, $request->protocol);
        self::assertSame('0.1', $request->client);
        self::assertSame('9a06581c-3bec-4cf1-8412-b2a40bb36a76', $request->uuid);
        self::assertSame('[{},{}]', $request->payload);
    }
}
