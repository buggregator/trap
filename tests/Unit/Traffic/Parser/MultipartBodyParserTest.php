<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Traffic\Parser;

use Buggregator\Trap\Tests\Unit\FiberTrait;
use Buggregator\Trap\Traffic\Message\Multipart\Field;
use Buggregator\Trap\Traffic\Message\Multipart\File;
use Buggregator\Trap\Traffic\Message\Multipart\Part;
use Buggregator\Trap\Traffic\Parser;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;

final class MultipartBodyParserTest extends TestCase
{
    use FiberTrait;

    public function testParse(): void
    {
        $body = $this->makeStream(
            <<<BODY
                --your-boundary\r
                Content-Type: text/plain; charset="utf-8"\r
                Content-Transfer-Encoding: quoted-printable\r
                Content-Disposition: inline\r
                \r
                Plain text email goes here!\r
                This is the fallback if email client does not support HTML\r
                \r
                --your-boundary\r
                Content-Type: text/html; charset="utf-8"\r
                Content-Transfer-Encoding: quoted-printable\r
                Content-Disposition: inline\r
                \r
                <h1>This is the HTML Section!</h1>\r
                <p>This is what displays in most modern email clients</p>\r
                \r
                --your-boundary--\r\n\r\n
                BODY,
        );

        $result = $this->parse($body, 'your-boundary');

        self::assertCount(2, $result);
        self::assertInstanceOf(Field::class, $result[0]);
        self::assertInstanceOf(Field::class, $result[1]);
    }

    public function testWithFileAttach(): void
    {
        $file1 = \file_get_contents(__DIR__ . '/../../../Stub/deburger.png');
        $file2 = \file_get_contents(__DIR__ . '/../../../Stub/buggregator.png');
        $body = $this->makeStream(
            <<<BODY
                --Asrf456BGe4h\r
                Content-Disposition: form-data; name="Authors"\r
                \r
                @roxblnfk and @butschster\r
                --Asrf456BGe4h\r
                Content-Disposition: form-data; name="MessageTitle"\r
                \r
                Hello guys! The Buggregator is a great tool!\r
                --Asrf456BGe4h\r
                Content-Disposition: form-data; name="MessageText"\r
                \r
                Do you know that Buggregator could be called Deburger? But we decided to name it Buggregator.\r
                --Asrf456BGe4h\r
                Content-Disposition: form-data; name="AttachedFile1"; filename="deburger.png"\r
                Content-Type: image/png\r
                \r
                $file1\r
                --Asrf456BGe4h\r
                Content-Disposition: form-data; name="AttachedFile2"; filename="buggregator.png"\r
                Content-Type: image/png\r
                \r
                $file2\r
                --Asrf456BGe4h--\r\n\r\n
                BODY,
        );

        $result = $this->parse($body, 'Asrf456BGe4h');

        self::assertCount(5, $result);
        self::assertInstanceOf(Field::class, $result[0]);
        self::assertInstanceOf(Field::class, $result[1]);

        // POST data
        self::assertSame('Authors', $result[0]->getName());
        self::assertSame('@roxblnfk and @butschster', $result[0]->getValue());
        self::assertSame('MessageTitle', $result[1]->getName());
        self::assertSame('Hello guys! The Buggregator is a great tool!', $result[1]->getValue());
        self::assertSame('MessageText', $result[2]->getName());
        self::assertSame(
            'Do you know that Buggregator could be called Deburger? But we decided to name it Buggregator.',
            $result[2]->getValue(),
        );

        $file = $result[3];
        // Uploaded files
        self::assertInstanceOf(File::class, $file);
        self::assertSame('AttachedFile1', $file->getName());
        self::assertSame('deburger.png', $file->getClientFilename());
        self::assertSame('image/png', $file->getClientMediaType());
        self::assertSame($file1, $file->getStream()->__toString());

        $file = $result[4];
        self::assertInstanceOf(File::class, $file);
        self::assertSame('AttachedFile2', $file->getName());
        self::assertSame('buggregator.png', $file->getClientFilename());
        self::assertSame('image/png', $file->getClientMediaType());
        self::assertSame($file2, $file->getStream()->__toString());
    }

    public function testBase64Encoded(): void
    {
        $file1 = \file_get_contents(__DIR__ . '/../../../Stub/deburger.png');
        $file2 = \file_get_contents(__DIR__ . '/../../../Stub/buggregator.png');

        $encoded1 = \base64_encode($file1);
        $encoded2 = \base64_encode($file2);
        $body = $this->makeStream(
            <<<BODY
                --Asrf456BGe4h\r
                Content-Type: image/png; name=4486bda9ad8b1f422deaf6a750194668@trap\r
                Content-Transfer-Encoding: base64\r
                Content-Disposition: inline; filename=logo-embeddable\r
                \r
                $encoded1\r
                --Asrf456BGe4h\r
                Content-Disposition: inline; name="AttachedFile2"; filename=logo-embeddable\r
                Content-Transfer-Encoding: base64\r
                Content-Type: image/png\r
                \r
                $encoded2\r
                --Asrf456BGe4h--\r\n\r\n
                BODY,
        );

        $result = $this->parse($body, 'Asrf456BGe4h');

        self::assertCount(2, $result);
        $file = $result[0];
        // Uploaded files
        self::assertInstanceOf(File::class, $file);
        self::assertNull($file->getName());
        self::assertSame('logo-embeddable', $file->getClientFilename());
        self::assertSame('image/png', $file->getClientMediaType());
        self::assertSame('4486bda9ad8b1f422deaf6a750194668@trap', $file->getEmbeddingId());
        self::assertSame($file1, $file->getStream()->__toString());

        $file = $result[1];
        self::assertInstanceOf(File::class, $file);
        self::assertSame('AttachedFile2', $file->getName());
        self::assertSame('logo-embeddable', $file->getClientFilename());
        self::assertSame('image/png', $file->getClientMediaType());
        self::assertSame('AttachedFile2', $file->getEmbeddingId());
        self::assertSame($file2, $file->getStream()->__toString());
    }

    private function makeStream(string $body): StreamInterface
    {
        $stream = Stream::create($body);
        $stream->rewind();
        return $stream;
    }

    /**
     * @param non-empty-string $boundary
     *
     * @return iterable<Part>
     */
    private function parse(StreamInterface $body, string $boundary): iterable
    {
        return $this->runInFiber(static fn() => Parser\Http::parseMultipartBody($body, $boundary));
    }
}
