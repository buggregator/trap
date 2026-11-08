<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Client\Caster;

use Buggregator\Trap\Client\Caster\EnumValue;
use Buggregator\Trap\Client\Caster\ProtobufCaster;
use Buggregator\Trap\Test\Proto\Message;
use Buggregator\Trap\Test\Proto\Message\Foo;
use Buggregator\Trap\Test\Proto\Message\Header;
use Buggregator\Trap\Test\Proto\Message\Metadata;
use Google\Protobuf\Internal\GPBType;
use Google\Protobuf\Internal\MapField;
use Google\Protobuf\Internal\RepeatedField;
use Symfony\Component\VarDumper\Caster\Caster;
use Symfony\Component\VarDumper\Cloner\Stub;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(ProtobufCaster::class)]
final class ProtobufCasterTest
{
    public function testCastDefaultMessageKeepsOnlyEnum(): void
    {
        $result = ProtobufCaster::cast(new Message(), [], new Stub(), false);

        Assert::same($result[Caster::PREFIX_VIRTUAL . 'message'], 'stub.proto.Message');
        $values = $result[Caster::PREFIX_VIRTUAL . 'values'];
        Assert::same(\array_keys($values), ['foo']);
        Assert::instanceOf($values['foo'], EnumValue::class);
        Assert::same($values['foo']->name, 'FOO');
        Assert::same($values['foo']->value, Foo::FOO);
    }

    public function testCastFilledMessageExtractsFieldValues(): void
    {
        $header = new Header(['key' => 'h', 'value' => 'v']);
        $message = new Message([
            'id' => 42,
            'command' => 'run',
            'payload' => 'body',
            'main_metadata' => [new Metadata(['key' => 'k', 'value' => 'v'])],
            'foo' => Foo::BAR,
            'is_foo' => true,
            'data' => "\x00\x01",
            'header' => $header,
            'mapa_mapa' => ['a' => 'b'],
        ]);

        $values = ProtobufCaster::cast($message, [], new Stub(), false)[Caster::PREFIX_VIRTUAL . 'values'];

        Assert::same($values['id'], 42);
        Assert::same($values['command'], 'run');
        Assert::same($values['payload'], 'body');
        Assert::same($values['is_foo'], true);
        Assert::same($values['data'], "\x00\x01");
        Assert::same($values['header'], $header);
        Assert::count($values['main_metadata'], 1);
        Assert::same(\iterator_to_array($values['mapa_mapa']), ['a' => 'b']);
        Assert::same($values['foo']->class, Foo::class);
        Assert::same($values['foo']->name, 'BAR');
        Assert::same($values['foo']->value, Foo::BAR);
        Assert::false(\array_key_exists('metadata', $values));
    }

    public function testCastRepeatedReturnsElements(): void
    {
        $field = new RepeatedField(GPBType::STRING);
        $field[] = 'a';
        $field[] = 'b';

        Assert::same(ProtobufCaster::castRepeated($field, [], new Stub(), false), ['a', 'b']);
    }

    public function testCastMapReturnsValuesUnderVirtualKey(): void
    {
        $field = new MapField(GPBType::STRING, GPBType::INT32);
        $field['x'] = 1;
        $field['y'] = 2;

        Assert::same(
            ProtobufCaster::castMap($field, [], new Stub(), false),
            [Caster::PREFIX_VIRTUAL . 'values' => ['x' => 1, 'y' => 2]],
        );
    }

    public function testCastEnumSetsStubClassAndExposesNameAndValue(): void
    {
        $stub = new Stub();

        $result = ProtobufCaster::castEnum(new EnumValue(Foo::class, 'BAR', 1), [], $stub, false);

        Assert::same($stub->class, Foo::class);
        Assert::same($result, [
            Caster::PREFIX_VIRTUAL . 'name' => 'BAR',
            Caster::PREFIX_VIRTUAL . 'value' => 1,
        ]);
    }
}
