<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Traffic\Message\Multipart;

use Buggregator\Trap\Traffic\Message\Multipart\Field;
use Testo\Assert;
use Testo\Test;

#[Test]
final class FieldTest
{
    public function testGetters(): void
    {
        $field = new Field(['Foo' => 'Bar'], 'name', 'value');

        Assert::same($field->getName(), 'name');
        Assert::same($field->getValue(), 'value');
        Assert::same($field->getHeaderLine('foo'), 'Bar');
    }

    public function testWithHeader(): void
    {
        $field = new Field(['Foo' => 'Bar'], 'name', 'value');
        $new = $field->withHeader('foo', 'baz');

        Assert::notSame($new, $field);
        Assert::same($field->getHeaderLine('foo'), 'Bar');
        Assert::same($new->getHeaderLine('foo'), 'baz');
    }

    public function testFromArray(): void
    {
        $field = Field::fromArray(['headers' => ['Foo' => ['Bar', 'Baz']], 'value' => 'bar']);

        Assert::null($field->getName());
        Assert::same($field->getValue(), 'bar');
        Assert::same($field->getHeader('foo'), ['Bar', 'Baz']);
    }

    public function testSerializeAndUnserialize(): void
    {
        $from = new Field(['Foo' => ['Bar', 'Baz']], 'message', 'foo bar baz');
        $to = Field::fromArray($from->jsonSerialize());

        Assert::equals($to, $from);
    }
}
