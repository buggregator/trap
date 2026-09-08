<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Application\Config\Internal;

use Buggregator\Trap\Application\Config\Internal\Attribute\Env;
use Buggregator\Trap\Application\Config\Internal\Attribute\InflectableConfig;
use Buggregator\Trap\Application\Config\Internal\Attribute\InputArgument;
use Buggregator\Trap\Application\Config\Internal\Attribute\InputOption;
use Buggregator\Trap\Application\Config\Internal\Attribute\XPath;
use Buggregator\Trap\Application\Config\Internal\ConfigInflector;
use Internal\Container\ObjectContainer;
use PHPUnit\Framework\TestCase;

final class ConfigInflectorTest extends TestCase
{
    public function testSimpleHydration(): void
    {
        $dto = new #[InflectableConfig] class {
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

        $this->inflect($dto, xml: $xml);

        self::assertTrue($dto->myBool);
        self::assertSame(200, $dto->myInt);
        self::assertSame('foo-bar', $dto->myString);
        self::assertSame(42.0, $dto->myFloat);
    }

    public function testNonExistingOptions(): void
    {
        $dto = new #[InflectableConfig] class {
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

        $this->inflect($dto, xml: $xml);

        self::assertSame(3.14, $dto->none1);
        self::assertSame(3.14, $dto->none2);
        self::assertSame(3.14, $dto->none3);
        self::assertSame(3.14, $dto->none4);
    }

    public function testAttributesOrder(): void
    {
        $dto = new #[InflectableConfig] class {
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

        $this->inflect($dto, xml: $xml, opts: ['test' => 13], args: ['test' => 69], env: ['test' => 0]);

        self::assertSame(42, $dto->int1);
        self::assertSame(0, $dto->int2);
        self::assertSame(69, $dto->int3);
    }

    private function inflect(
        object $config,
        ?string $xml = null,
        array $opts = [],
        array $args = [],
        array $env = [],
    ): void {
        (new ConfigInflector(env: $env, inputArguments: $args, inputOptions: $opts, xml: $xml))
            ->inflect($config, new ObjectContainer());
    }
}
