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
use Testo\Assert;
use Testo\Test;

#[Test]
final class ConfigInflectorTest
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

        Assert::true($dto->myBool);
        Assert::same($dto->myInt, 200);
        Assert::same($dto->myString, 'foo-bar');
        Assert::same($dto->myFloat, 42.0);
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

        Assert::same($dto->none1, 3.14);
        Assert::same($dto->none2, 3.14);
        Assert::same($dto->none3, 3.14);
        Assert::same($dto->none4, 3.14);
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

        Assert::same($dto->int1, 42);
        Assert::same($dto->int2, 0);
        Assert::same($dto->int3, 69);
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
