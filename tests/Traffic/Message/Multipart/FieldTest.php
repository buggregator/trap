<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Traffic\Message\Multipart;

use Buggregator\Trap\Traffic\Message\Multipart\Field;
use PHPUnit\Framework\TestCase;

final class FieldTest extends TestCase
{
    public function testGetters(): void
    {
        $field = new Field(['Foo' => 'Bar'], 'name', 'value');

        $this->assertSame('name', $field->getName());
        $this->assertSame('value', $field->getValue());
        $this->assertSame('Bar', $field->getHeaderLine('foo'));
    }

    public function testWithHeader(): void
    {
        $field = new Field(['Foo' => 'Bar'], 'name', 'value');
        $new = $field->withHeader('foo', 'baz');

        $this->assertNotSame($field, $new);
        $this->assertSame('Bar', $field->getHeaderLine('foo'));
        $this->assertSame('baz', $new->getHeaderLine('foo'));
    }

    public function testFromArray(): void
    {
        $field = Field::fromArray(['headers' => ['Foo' => ['Bar', 'Baz']], 'value' => 'bar']);

        $this->assertNull($field->getName());
        $this->assertSame('bar', $field->getValue());
        $this->assertSame(['Bar', 'Baz'], $field->getHeader('foo'));
    }

    public function testSerializeAndUnserialize(): void
    {
        $from = new Field(['Foo' => ['Bar', 'Baz']], 'message', 'foo bar baz');
        $to = Field::fromArray($from->jsonSerialize());

        $this->assertEquals($from, $to);
    }
}
