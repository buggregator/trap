<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Application\Config\Internal;

use Buggregator\Trap\Application\Config\Internal\Attribute\Env;
use Buggregator\Trap\Application\Config\Internal\Attribute\InflectableConfig;
use Buggregator\Trap\Application\Config\Internal\Attribute\InputArgument;
use Buggregator\Trap\Application\Config\Internal\Attribute\InputOption;
use Buggregator\Trap\Application\Config\Internal\Attribute\PhpIni;
use Buggregator\Trap\Application\Config\Internal\Attribute\XPath;
use Buggregator\Trap\Application\Config\Internal\Attribute\XPathEmbed;
use Buggregator\Trap\Application\Config\Internal\Attribute\XPathEmbedList;
use Buggregator\Trap\Application\Config\Internal\ConfigInflector;
use Buggregator\Trap\Logger;
use Buggregator\Trap\Tests\Unit\Application\Config\Internal\Stub\EmbeddedItem;
use Buggregator\Trap\Tests\Unit\Application\Config\Internal\Stub\IntBackedLevel;
use Buggregator\Trap\Tests\Unit\Application\Config\Internal\Stub\PureColor;
use Buggregator\Trap\Tests\Unit\Application\Config\Internal\Stub\StringBackedMode;
use Buggregator\Trap\Tests\Unit\Application\Config\Internal\Stub\UnsupportedAttribute;
use Internal\Container\Container;
use Internal\Container\ObjectContainer;
use Symfony\Component\Console\Output\BufferedOutput;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(ConfigInflector::class)]
final class ConfigInflectorTest
{
    private string|false $errorPrependString = false;

    #[BeforeTest]
    public function rememberIni(): void
    {
        $this->errorPrependString = \ini_get('error_prepend_string');
    }

    #[AfterTest]
    public function restoreIni(): void
    {
        $this->errorPrependString === false or \ini_set('error_prepend_string', $this->errorPrependString);
    }

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

    public function testObjectWithoutInflectableConfigIsLeftUntouched(): void
    {
        $dto = new class {
            #[Env('test')]
            public string $value = 'default';
        };

        $this->inflect($dto, env: ['test' => 'injected']);

        Assert::same($dto->value, 'default');
    }

    public function testPropertyWithoutConfigAttributeIsLeftUntouched(): void
    {
        $dto = new #[InflectableConfig] class {
            public string $plain = 'default';

            #[Env('test')]
            public string $value = 'default';
        };

        $this->inflect($dto, env: ['test' => 'injected']);

        Assert::same($dto->plain, 'default');
        Assert::same($dto->value, 'injected');
    }

    public function testUnsupportedConfigAttributeIsIgnored(): void
    {
        $dto = new #[InflectableConfig] class {
            #[UnsupportedAttribute]
            #[Env('test')]
            public string $value = 'default';
        };

        $this->inflect($dto, env: ['test' => 'from-env']);

        Assert::same($dto->value, 'from-env');
    }

    public function testPhpIniOptionIsInjected(): void
    {
        \ini_set('error_prepend_string', 'prefix');
        $dto = new #[InflectableConfig] class {
            #[PhpIni('error_prepend_string')]
            public string $value = 'default';
        };

        $this->inflect($dto);

        Assert::same($dto->value, 'prefix');
    }

    public function testEmptyPhpIniOptionFallsBackToNextSource(): void
    {
        \ini_set('error_prepend_string', '');
        $dto = new #[InflectableConfig] class {
            #[PhpIni('error_prepend_string')]
            #[Env('test')]
            public string $value = 'default';
        };

        $this->inflect($dto, env: ['test' => 'from-env']);

        Assert::same($dto->value, 'from-env');
    }

    public function testUnknownPhpIniOptionFallsBackToNextSource(): void
    {
        $dto = new #[InflectableConfig] class {
            #[PhpIni('trap.no-such-option')]
            #[Env('test')]
            public string $value = 'default';
        };

        $this->inflect($dto, env: ['test' => 'from-env']);

        Assert::same($dto->value, 'from-env');
    }

    #[DataSet(['a,b,c', ['a', 'b', 'c']], 'comma separated string')]
    #[DataSet([['x', 'y'], ['x', 'y']], 'array as is')]
    #[DataSet([5, [5]], 'scalar wrapped')]
    public function testArrayPropertyCasting(mixed $value, array $expected): void
    {
        $dto = new #[InflectableConfig] class {
            #[Env('test')]
            public array $value = ['default'];
        };

        $this->inflect($dto, env: ['test' => $value]);

        Assert::same($dto->value, $expected);
    }

    public function testEmptyArrayValueIsSkipped(): void
    {
        $dto = new #[InflectableConfig] class {
            #[Env('test')]
            public array $value = ['default'];
        };

        $this->inflect($dto, env: ['test' => []]);

        Assert::same($dto->value, ['default']);
    }

    public function testEmptyStringBecomesNullForNullableProperty(): void
    {
        $dto = new #[InflectableConfig] class {
            #[Env('test')]
            public ?string $value = 'default';
        };

        $this->inflect($dto, env: ['test' => '']);

        Assert::null($dto->value);
    }

    public function testUnionTypedPropertyGetsRawValue(): void
    {
        $dto = new #[InflectableConfig] class {
            #[Env('test')]
            public int|string $value = 0;
        };

        $this->inflect($dto, env: ['test' => '42']);

        Assert::same($dto->value, '42');
    }

    public function testClassTypedPropertyGetsValueAsIs(): void
    {
        $object = new \ArrayObject([1]);
        $dto = new #[InflectableConfig] class {
            #[Env('test')]
            public ?\ArrayObject $value = null;
        };

        $this->inflect($dto, env: ['test' => $object]);

        Assert::same($dto->value, $object);
    }

    public function testStringBackedEnumIsResolvedByValue(): void
    {
        $dto = new #[InflectableConfig] class {
            #[Env('test')]
            public ?StringBackedMode $value = null;
        };

        $this->inflect($dto, env: ['test' => 'beta']);

        Assert::same($dto->value, StringBackedMode::Beta);
    }

    public function testIntBackedEnumIsResolvedFromNumericString(): void
    {
        $dto = new #[InflectableConfig] class {
            #[Env('test')]
            public ?IntBackedLevel $value = null;
        };

        $this->inflect($dto, env: ['test' => '2']);

        Assert::same($dto->value, IntBackedLevel::High);
    }

    #[DataSet(['GREEN'], 'upper case')]
    #[DataSet(['green'], 'lower case')]
    public function testPureEnumIsResolvedByCaseNameIgnoringCase(string $value): void
    {
        $dto = new #[InflectableConfig] class {
            #[Env('test')]
            public ?PureColor $value = null;
        };

        $this->inflect($dto, env: ['test' => $value]);

        Assert::same($dto->value, PureColor::Green);
    }

    public function testInvalidEnumValueIsLoggedAndNextSourceIsUsed(): void
    {
        $output = new BufferedOutput();
        $container = new ObjectContainer();
        $container->set(new Logger($output));
        $dto = new #[InflectableConfig] class {
            #[Env('color')]
            #[InputOption('color')]
            public PureColor $value = PureColor::Red;
        };

        $this->inflect($dto, opts: ['color' => 'green'], env: ['color' => 'purple'], container: $container);

        Assert::same($dto->value, PureColor::Green);
        Assert::string($output->fetch())->contains('Invalid enum value `purple`');
    }

    public function testInvalidBackedEnumValueKeepsDefault(): void
    {
        $dto = new #[InflectableConfig] class {
            #[Env('mode')]
            public StringBackedMode $value = StringBackedMode::Alpha;
        };

        $this->inflect($dto, env: ['mode' => 'gamma']);

        Assert::same($dto->value, StringBackedMode::Alpha);
    }

    public function testFailureToResolveLoggerIsIgnored(): void
    {
        $container = new ObjectContainer();
        $container->bind(Logger::class, static fn(): never => throw new \RuntimeException('No logger yet'));
        $dto = new #[InflectableConfig] class {
            #[Env('color')]
            public PureColor $value = PureColor::Red;
        };

        $this->inflect($dto, env: ['color' => 'purple'], container: $container);

        Assert::same($dto->value, PureColor::Red);
    }

    public function testXPathWithoutXmlFallsBackToNextSource(): void
    {
        $dto = new #[InflectableConfig] class {
            #[XPath('/trap/@value')]
            #[Env('test')]
            public string $value = 'default';
        };

        $this->inflect($dto, env: ['test' => 'from-env']);

        Assert::same($dto->value, 'from-env');
    }

    public function testXPathEmbedHydratesFirstMatch(): void
    {
        $dto = new #[InflectableConfig] class {
            #[XPathEmbed('/trap/item', EmbeddedItem::class)]
            public ?EmbeddedItem $item = null;
        };

        $this->inflect($dto, xml: '<trap><item name="first"/><item name="second"/></trap>');

        Assert::instanceOf($dto->item, EmbeddedItem::class);
        Assert::same($dto->item->name, 'first');
    }

    #[DataSet([null], 'no xml')]
    #[DataSet(['<trap><other/></trap>'], 'no matching node')]
    public function testXPathEmbedWithoutMatchKeepsDefault(?string $xml): void
    {
        $dto = new #[InflectableConfig] class {
            #[XPathEmbed('/trap/item', EmbeddedItem::class)]
            public ?EmbeddedItem $item = null;
        };

        $this->inflect($dto, xml: $xml);

        Assert::null($dto->item);
    }

    public function testXPathEmbedWithInvalidExpressionKeepsDefault(): void
    {
        $dto = new #[InflectableConfig] class {
            #[XPathEmbed('/trap/[[', EmbeddedItem::class)]
            public ?EmbeddedItem $item = null;
        };

        $this->inflect($dto, xml: '<trap><item name="x"/></trap>');

        Assert::null($dto->item);
    }

    public function testXPathEmbedListHydratesEveryMatch(): void
    {
        $dto = new #[InflectableConfig] class {
            #[XPathEmbedList('/trap/item', EmbeddedItem::class)]
            public array $items = [];
        };

        $this->inflect($dto, xml: '<trap><item name="first"/><item name="second"/></trap>');

        Assert::count($dto->items, 2);
        Assert::same($dto->items[0]->name, 'first');
        Assert::same($dto->items[1]->name, 'second');
    }

    public function testXPathEmbedListWithoutXmlKeepsDefault(): void
    {
        $default = new EmbeddedItem();
        $dto = new #[InflectableConfig] class {
            #[XPathEmbedList('/trap/item', EmbeddedItem::class)]
            public array $items = [];
        };
        $dto->items = [$default];

        $this->inflect($dto);

        Assert::same($dto->items, [$default]);
    }

    public function testXPathEmbedListWithInvalidExpressionIsLogged(): void
    {
        $output = new BufferedOutput();
        $container = new ObjectContainer();
        $container->set(new Logger($output));
        $dto = new #[InflectableConfig] class {
            #[XPathEmbedList('/trap/[[', EmbeddedItem::class)]
            public array $items = [];
        };

        $this->inflect($dto, xml: '<trap><item name="x"/></trap>', container: $container);

        Assert::same($dto->items, []);
        Assert::string($output->fetch())->contains('Invalid XPath `/trap/[[`');
    }

    private function inflect(
        object $config,
        ?string $xml = null,
        array $opts = [],
        array $args = [],
        array $env = [],
        ?Container $container = null,
    ): void {
        (new ConfigInflector(env: $env, inputArguments: $args, inputOptions: $opts, xml: $xml))
            ->inflect($config, $container ?? new ObjectContainer());
    }
}
