<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Traffic\Message\Multipart;

use Buggregator\Trap\Support\StreamHelper;
use Buggregator\Trap\Traffic\Message\Multipart\File;
use PHPUnit\Framework\TestCase;

class FileTest extends TestCase
{
    /**
     * @test
     */
    public function getters(): void
    {
        $file = new File(['Foo' => 'Bar'], 'name', 'filename');

        $this->assertSame('name', $file->getName());
        $this->assertSame('filename', $file->getClientFilename());
        $this->assertSame('Bar', $file->getHeaderLine('foo'));
    }

    /**
     * @test
     */
    public function with_header(): void
    {
        $file = new File(['Foo' => 'Bar'], 'name', 'filename');
        $new = $file->withHeader('foo', 'baz');

        $this->assertNotSame($file, $new);
        $this->assertSame('Bar', $file->getHeaderLine('foo'));
        $this->assertSame('baz', $new->getHeaderLine('foo'));
    }

    /**
     * @test
     */
    public function from_array(): void
    {
        $field = File::fromArray([
            'headers' => ['Foo' => ['Bar', 'Baz'], 'Content-Type' => 'image/jpeg'],
            'fileName' => 'bar.jpg',
            'size' => 10,
        ]);

        $this->assertNull($field->getName());
        $this->assertSame(10, $field->getSize());
        $this->assertSame('bar.jpg', $field->getClientFilename());
        $this->assertSame('image/jpeg', $field->getClientMediaType());
        $this->assertSame(['Bar', 'Baz'], $field->getHeader('foo'));
    }

    /**
     * @test
     */
    public function serialize_and_unserialize(): void
    {
        $from = new File(['Foo' => ['Bar', 'Baz']], 'message', 'icon.ico');
        $stream = StreamHelper::createFileStream();
        $stream->write('foo bar baz');
        $from->setStream($stream);

        $to = File::fromArray($from->jsonSerialize());

        $this->assertSame($from->getHeaders(), $to->getHeaders());
        $this->assertSame($from->getSize(), $to->getSize());
        $this->assertSame($from->getClientFilename(), $to->getClientFilename());
        $this->assertSame($from->getClientMediaType(), $to->getClientMediaType());
    }
}
