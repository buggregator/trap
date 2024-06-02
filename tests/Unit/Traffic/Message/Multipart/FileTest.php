<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Traffic\Message\Multipart;

use Buggregator\Trap\Support\StreamHelper;
use Buggregator\Trap\Traffic\Message\Multipart\File;
use PHPUnit\Framework\TestCase;

class FileTest extends TestCase
{
    public function testGetters(): void
    {
        $file = new File(['Foo' => 'Bar'], 'name', 'filename');

        self::assertSame('name', $file->getName());
        self::assertSame('filename', $file->getClientFilename());
        self::assertSame('Bar', $file->getHeaderLine('foo'));
    }

    public function testWithHeader(): void
    {
        $file = new File(['Foo' => 'Bar'], 'name', 'filename');
        $new = $file->withHeader('foo', 'baz');

        self::assertNotSame($file, $new);
        self::assertSame('Bar', $file->getHeaderLine('foo'));
        self::assertSame('baz', $new->getHeaderLine('foo'));
    }

    public function testFromArray(): void
    {
        $field = File::fromArray([
            'headers' => ['Foo' => ['Bar', 'Baz'], 'Content-Type' => 'image/jpeg'],
            'fileName' => 'bar.jpg',
            'size' => 10,
        ]);

        self::assertNull($field->getName());
        self::assertSame(10, $field->getSize());
        self::assertSame('bar.jpg', $field->getClientFilename());
        self::assertSame('image/jpeg', $field->getClientMediaType());
        self::assertSame(['Bar', 'Baz'], $field->getHeader('foo'));
    }

    public function testSerializeAndUnserialize(): void
    {
        $from = new File(['Foo' => ['Bar', 'Baz']], 'message', 'icon.ico');
        $stream = StreamHelper::createFileStream();
        $stream->write('foo bar baz');
        $from->setStream($stream);

        $to = File::fromArray($from->jsonSerialize());

        self::assertSame($from->getHeaders(), $to->getHeaders());
        self::assertSame($from->getSize(), $to->getSize());
        self::assertSame($from->getClientFilename(), $to->getClientFilename());
        self::assertSame($from->getClientMediaType(), $to->getClientMediaType());
    }
}
