<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Traffic\Parser;

use Buggregator\Trap\Tests\Unit\FiberTrait;
use Buggregator\Trap\Traffic\Message\Multipart\Field;
use Buggregator\Trap\Traffic\Message\Multipart\File;
use Buggregator\Trap\Traffic\Message\Multipart\Part;
use Buggregator\Trap\Traffic\Parser;
use Buggregator\Trap\Traffic\Parser\MultipartType;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\StreamInterface;
use Testo\Assert;
use Testo\Test;

final class MultipartBodyParserTest
{
    use FiberTrait;

    #[Test]
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

        Assert::count($result, 2);
        Assert::instanceOf($result[0], Field::class);
        Assert::instanceOf($result[1], Field::class);
    }

    #[Test]
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

        Assert::count($result, 5);
        Assert::instanceOf($result[0], Field::class);
        Assert::instanceOf($result[1], Field::class);

        // POST data
        Assert::same($result[0]->getName(), 'Authors');
        Assert::same($result[0]->getValue(), '@roxblnfk and @butschster');
        Assert::same($result[1]->getName(), 'MessageTitle');
        Assert::same($result[1]->getValue(), 'Hello guys! The Buggregator is a great tool!');
        Assert::same($result[2]->getName(), 'MessageText');
        Assert::same($result[2]->getValue(), 'Do you know that Buggregator could be called Deburger? But we decided to name it Buggregator.');

        $file = $result[3];
        // Uploaded files
        Assert::instanceOf($file, File::class);
        Assert::same($file->getName(), 'AttachedFile1');
        Assert::same($file->getClientFilename(), 'deburger.png');
        Assert::same($file->getClientMediaType(), 'image/png');
        Assert::same($file->getStream()->__toString(), $file1);

        $file = $result[4];
        Assert::instanceOf($file, File::class);
        Assert::same($file->getName(), 'AttachedFile2');
        Assert::same($file->getClientFilename(), 'buggregator.png');
        Assert::same($file->getClientMediaType(), 'image/png');
        Assert::same($file->getStream()->__toString(), $file2);
    }

    #[Test]
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

        Assert::count($result, 2);
        $file = $result[0];
        // Uploaded files
        Assert::instanceOf($file, File::class);
        Assert::null($file->getName());
        Assert::same($file->getClientFilename(), 'logo-embeddable');
        Assert::same($file->getClientMediaType(), 'image/png');
        Assert::same($file->getEmbeddingId(), '4486bda9ad8b1f422deaf6a750194668@trap');
        Assert::same($file->getStream()->__toString(), $file1);

        $file = $result[1];
        Assert::instanceOf($file, File::class);
        Assert::same($file->getName(), 'AttachedFile2');
        Assert::same($file->getClientFilename(), 'logo-embeddable');
        Assert::same($file->getClientMediaType(), 'image/png');
        Assert::same($file->getEmbeddingId(), 'AttachedFile2');
        Assert::same($file->getStream()->__toString(), $file2);
    }

    /**
     * Simple multipart/mixed message without nested parts.
     */
    #[Test]
    public function testMultipartMixed(): void
    {
        $body = $this->makeStream(
            <<<BODY
                --40ugHb8e\r
                Content-Type: text/plain; charset=utf-8\r
                Content-Transfer-Encoding: quoted-printable\r
                \r
                Test Body\r
                --40ugHb8e\r
                Content-Type: text/plain; name=test.txt\r
                Content-Transfer-Encoding: base64\r
                Content-Disposition: attachment; name=test.txt; filename=test.txt\r
                \r
                c2RnCg==\r
                --40ugHb8e--\r
                \r
                .\r

                BODY,
        );

        $result = $this->parse($body, '40ugHb8e', MultipartType::Mixed);


        Assert::count($result, 2);
        // Field
        $file = $result[0];
        Assert::instanceOf($file, Field::class);
        Assert::null($file->getName());
        Assert::same($file->getValue(), 'Test Body');

        // Attached file
        $file = $result[1];
        Assert::instanceOf($file, File::class);
        Assert::same($file->getName(), 'test.txt');
        Assert::same($file->getClientFilename(), 'test.txt');
        Assert::same($file->getClientMediaType(), 'text/plain');
        Assert::null($file->getEmbeddingId());
        Assert::same($file->getStream()->__toString(), "sdg\n");
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
    private function parse(
        StreamInterface $body,
        string $boundary,
        MultipartType $type = MultipartType::FormData,
    ): iterable {
        return $this->runInFiber(static fn() => Parser\Http::parseMultipartBody($body, $boundary));
    }
}
