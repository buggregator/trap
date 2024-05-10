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

    /**
     * @test
     */
    #[DataProvider('payloadsDataProvider')]
    public function is_suppoerted(string $payload, bool $isSupported): void
    {
        $decoder = new V1();

        $this->assertSame($isSupported, $decoder->isSupport($payload));
    }

    /**
     * @test
     */
    public function decode(): void
    {
        $decoder = new V1();

        $request = $decoder->decode('1|0.1|9a06581c-3bec-4cf1-8412-b2a40bb36a76|[{},{}]');

        $this->assertSame(1, $request->protocol);
        $this->assertSame('0.1', $request->client);
        $this->assertSame('9a06581c-3bec-4cf1-8412-b2a40bb36a76', $request->uuid);
        $this->assertSame('[{},{}]', $request->payload);
    }
}
