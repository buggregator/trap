<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Service\Config;

use Buggregator\Trap\Logger;
use Buggregator\Trap\Service\Config\ConfigLoader;
use Buggregator\Trap\Service\Config\XPath;
use PHPUnit\Framework\TestCase;

final class ConfigLoaderTest extends TestCase
{
    public function testSimpleHydration(): void
    {
        $dto = new class() {
            #[XPath('/trap/container/@myBool')]
            public bool $myBool;
            #[XPath('/trap/container/MyInt/@value')]
            public int $myInt;
            #[XPath('/trap/@my-string')]
            public string $myString;
            #[XPath('/trap/container/MyFloat/@value')]
            public float $myFloat;
        };

        $xml = <<<'XML'
            <?xml version="1.0"?>
            <trap my-string="foo-bar">
                <container myBool="true">
                    <MyInt value="200"/>
                    <MyFloat value="42"/>
                </container>
            </trap>
            XML;

        $loader = new ConfigLoader(new Logger(), null, fn() => $xml);
        $loader->hidrate($dto);

        self::assertTrue($dto->myBool);
        self::assertSame(200, $dto->myInt);
        self::assertSame('foo-bar', $dto->myString);
        self::assertSame(42.0, $dto->myFloat);
    }
}
