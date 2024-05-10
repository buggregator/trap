<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Traffic\Message\Multipart;

use Buggregator\Trap\Traffic\Message\Multipart\Field;
use PHPUnit\Framework\TestCase;

final class FieldTest extends TestCase
{
    /**
     * @test
     */
    public function getters(): void
    {
        $field = new Field(['Foo' => 'Bar'], 'name', 'value');

        $this->assertSame('name', $field->getName());
        $this->assertSame('value', $field->getValue());
        $this->assertSame('Bar', $field->getHeaderLine('foo'));
    }

    /**
     * @test
     */
    public function with_header(): void
    {
        $field = new Field(['Foo' => 'Bar'], 'name', 'value');
        $new = $field->withHeader('foo', 'baz');

        $this->assertNotSame($field, $new);
        $this->assertSame('Bar', $field->getHeaderLine('foo'));
        $this->assertSame('baz', $new->getHeaderLine('foo'));
    }

    /**
     * @test
     */
    public function from_array(): void
    {
        $field = Field::fromArray(['headers' => ['Foo' => ['Bar', 'Baz']], 'value' => 'bar']);

        $this->assertNull($field->getName());
        $this->assertSame('bar', $field->getValue());
        $this->assertSame(['Bar', 'Baz'], $field->getHeader('foo'));
    }

    /**
     * @test
     */
    public function serialize_and_unserialize(): void
    {
        $from = new Field(['Foo' => ['Bar', 'Baz']], 'message', 'foo bar baz');
        $to = Field::fromArray($from->jsonSerialize());

        $this->assertEquals($from, $to);
    }
}
