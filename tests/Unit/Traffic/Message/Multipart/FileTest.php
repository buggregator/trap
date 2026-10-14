<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Traffic\Message\Multipart;

use Buggregator\Trap\Support\StreamHelper;
use Buggregator\Trap\Traffic\Message\Multipart\File;
use Testo\Assert;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
final class FileTest
{
    public static function provideEmbeddings(): iterable
    {
        yield [['Content-Type' => 'image/jpeg'], null];
        yield [['Content-Type' => 'image/jpeg', 'Content-Disposition' => 'inline; filename="foo.jpg"'], null];
        yield [['Content-Type' => 'image/jpeg', 'Content-Disposition' => 'inline; filename="foo.jpg"; id="bar"'], null];
        yield [['Content-Type' => 'image/png; name="embedding-name"; id="bar"'], 'embedding-name'];
        yield [['Content-Type' => 'image/png; a-name=test; name=embedding-name; b-name=test'], 'embedding-name'];
        yield [['Content-Type' => 'image/png; name=\'embedding-name\''], 'embedding-name'];
        yield [['Content-Disposition' => 'inline; name="embedding-name"'], 'embedding-name'];
        yield [['Content-Disposition' => 'inline; ; a-name="a"; name=embedding; file-name=3'], 'embedding'];
        yield [['Content-Disposition' => 'inline; name=\'embedding-1\''], 'embedding-1'];
    }

    public function testGetters(): void
    {
        $file = new File(['Foo' => 'Bar'], 'name', 'filename');

        Assert::same($file->getName(), 'name');
        Assert::same($file->getClientFilename(), 'filename');
        Assert::same($file->getHeaderLine('foo'), 'Bar');
    }

    public function testWithHeader(): void
    {
        $file = new File(['Foo' => 'Bar'], 'name', 'filename');
        $new = $file->withHeader('foo', 'baz');

        Assert::notSame($new, $file);
        Assert::same($file->getHeaderLine('foo'), 'Bar');
        Assert::same($new->getHeaderLine('foo'), 'baz');
    }

    #[DataProvider('provideEmbeddings')]
    public function testEmbeddingId(array $headers, ?string $result): void
    {
        $field = File::fromArray([
            'headers' => $headers,
        ]);

        Assert::same($field->getEmbeddingId(), $result);
    }

    public function testFromArray(): void
    {
        $field = File::fromArray([
            'headers' => ['Foo' => ['Bar', 'Baz'], 'Content-Type' => 'image/jpeg'],
            'fileName' => 'bar.jpg',
            'size' => 10,
        ]);

        Assert::null($field->getName());
        Assert::same($field->getSize(), 10);
        Assert::same($field->getClientFilename(), 'bar.jpg');
        Assert::same($field->getClientMediaType(), 'image/jpeg');
        Assert::same($field->getHeader('foo'), ['Bar', 'Baz']);
    }

    public function testSerializeAndUnserialize(): void
    {
        $from = new File(['Foo' => ['Bar', 'Baz']], 'message', 'icon.ico');
        $stream = StreamHelper::createFileStream();
        $stream->write('foo bar baz');
        $from->setStream($stream);

        $to = File::fromArray($from->jsonSerialize());

        Assert::same($to->getHeaders(), $from->getHeaders());
        Assert::same($to->getSize(), $from->getSize());
        Assert::same($to->getClientFilename(), $from->getClientFilename());
        Assert::same($to->getClientMediaType(), $from->getClientMediaType());
    }
}
