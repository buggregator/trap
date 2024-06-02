<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Traffic\Message\Multipart;

use Buggregator\Trap\Traffic\Message\Multipart\Field;
use PHPUnit\Framework\TestCase;

final class FieldTest extends TestCase
{
    public function testGetters(): void
    {
        $field = new Field(['Foo' => 'Bar'], 'name', 'value');

        self::assertSame('name', $field->getName());
        self::assertSame('value', $field->getValue());
        self::assertSame('Bar', $field->getHeaderLine('foo'));
    }

    public function testWithHeader(): void
    {
        $field = new Field(['Foo' => 'Bar'], 'name', 'value');
        $new = $field->withHeader('foo', 'baz');

        self::assertNotSame($field, $new);
        self::assertSame('Bar', $field->getHeaderLine('foo'));
        self::assertSame('baz', $new->getHeaderLine('foo'));
    }

    public function testFromArray(): void
    {
        $field = Field::fromArray(['headers' => ['Foo' => ['Bar', 'Baz']], 'value' => 'bar']);

        self::assertNull($field->getName());
        self::assertSame('bar', $field->getValue());
        self::assertSame(['Bar', 'Baz'], $field->getHeader('foo'));
    }

    public function testSerializeAndUnserialize(): void
    {
        $from = new Field(['Foo' => ['Bar', 'Baz']], 'message', 'foo bar baz');
        $to = Field::fromArray($from->jsonSerialize());

        self::assertEquals($from, $to);
    }
}
