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

        $this->assertCount(2, $result);
        $this->assertInstanceOf(Field::class, $result[0]);
        $this->assertInstanceOf(Field::class, $result[1]);
    }

    public function testWithFileAttach(): void
    {
        $file1 = \file_get_contents(__DIR__ . '/../../../Stub/deburger.png');
        $file2 = \file_get_contents(__DIR__ . '/../../../Stub/buggregator.png');
        $body = $this->makeStream(<<<BODY
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

        $this->assertCount(5, $result);
        $this->assertInstanceOf(Field::class, $result[0]);
        $this->assertInstanceOf(Field::class, $result[1]);

        // POST data
        $this->assertSame('Authors', $result[0]->getName());
        $this->assertSame('@roxblnfk and @butschster', $result[0]->getValue());
        $this->assertSame('MessageTitle', $result[1]->getName());
        $this->assertSame('Hello guys! The Buggregator is a great tool!', $result[1]->getValue());
        $this->assertSame('MessageText', $result[2]->getName());
        $this->assertSame(
            'Do you know that Buggregator could be called Deburger? But we decided to name it Buggregator.',
            $result[2]->getValue(),
        );

        $file = $result[3];
        // Uploaded files
        $this->assertInstanceOf(File::class, $file);
        $this->assertSame('AttachedFile1', $file->getName());
        $this->assertSame('deburger.png', $file->getClientFilename());
        $this->assertSame('image/png', $file->getClientMediaType());
        $this->assertSame($file1, $file->getStream()->__toString());

        $file = $result[4];
        $this->assertInstanceOf(File::class, $file);
        $this->assertSame('AttachedFile2', $file->getName());
        $this->assertSame('buggregator.png', $file->getClientFilename());
        $this->assertSame('image/png', $file->getClientMediaType());
        $this->assertSame($file2, $file->getStream()->__toString());
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
