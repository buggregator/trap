<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Service\Config;

use Buggregator\Trap\Bootstrap;
use Buggregator\Trap\Service\Config\ConfigLoader;
use Buggregator\Trap\Service\Config\Env;
use Buggregator\Trap\Service\Config\InputArgument;
use Buggregator\Trap\Service\Config\InputOption;
use Buggregator\Trap\Service\Config\XPath;
use PHPUnit\Framework\TestCase;

final class ConfigLoaderTest extends TestCase
{
    public function testSimpleHydration(): void
    {
        $dto = new class {
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

        $this->createConfigLoader(xml: $xml)->hidrate($dto);

        self::assertTrue($dto->myBool);
        self::assertSame(200, $dto->myInt);
        self::assertSame('foo-bar', $dto->myString);
        self::assertSame(42.0, $dto->myFloat);
    }

    public function testNonExistingOptions(): void
    {
        $dto = new class {
            #[XPath('/trap/container/Nothing/@value')]
            public float $none1 = 3.14;

            #[Env('f--o--o')]
            public float $none2 = 3.14;

            #[InputOption('f--o--o')]
            public float $none3 = 3.14;

            #[InputArgument('f--o--o')]
            public float $none4 = 3.14;
        };
        $xml = <<<'XML'
            <?xml version="1.0"?>
            <trap my-string="foo-bar"> </trap>
            XML;

        $this->createConfigLoader(xml: $xml)->hidrate($dto);

        self::assertSame(3.14, $dto->none1);
        self::assertSame(3.14, $dto->none2);
        self::assertSame(3.14, $dto->none3);
        self::assertSame(3.14, $dto->none4);
    }

    public function testAttributesOrder(): void
    {
        $dto = new class {
            #[XPath('/test/@foo')]
            #[InputArgument('test')]
            #[InputOption('test')]
            #[Env('test')]
            public int $int1;

            #[Env('test')]
            #[InputArgument('test')]
            #[XPath('/test/@foo')]
            #[InputOption('test')]
            public int $int2;

            #[InputArgument('test')]
            #[Env('test')]
            #[XPath('/test/@foo')]
            #[InputOption('test')]
            public int $int3;
        };
        $xml = <<<'XML'
            <?xml version="1.0"?>
            <test foo="42">
            </test>
            XML;

        $this
            ->createConfigLoader(xml: $xml, opts: ['test' => 13], args: ['test' => 69], env: ['test' => 0])
            ->hidrate($dto);

        self::assertSame(42, $dto->int1);
        self::assertSame(0, $dto->int2);
        self::assertSame(69, $dto->int3);
    }

    private function createConfigLoader(
        ?string $xml = null,
        array $opts = [],
        array $args = [],
        array $env = [],
    ): ConfigLoader {
        return Bootstrap::init()
            ->withConfig($xml, $opts, $args, $env)
            ->finish()
            ->get(ConfigLoader::class);
    }
}
