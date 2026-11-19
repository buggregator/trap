<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Server\Traffic\Parser;

use Buggregator\Trap\Server\Traffic\Message\Multipart\Field;
use Buggregator\Trap\Server\Traffic\Message\Multipart\File;
use Buggregator\Trap\Server\Traffic\Message\Multipart\Part;
use Buggregator\Trap\Server\Traffic\Parser;
use Buggregator\Trap\Server\Traffic\Parser\MultipartType;
use Buggregator\Trap\Tests\Unit\FiberTrait;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\StreamInterface;
use Testo\Assert;
use Testo\Data\DataSet;
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
        $file1 = \file_get_contents(__DIR__ . '/../../../../Stub/deburger.png');
        $file2 = \file_get_contents(__DIR__ . '/../../../../Stub/buggregator.png');
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
        $file1 = \file_get_contents(__DIR__ . '/../../../../Stub/deburger.png');
        $file2 = \file_get_contents(__DIR__ . '/../../../../Stub/buggregator.png');

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

    /**
     * A malformed escape stays as written, and the parts after the file are parsed.
     */
    #[Test]
    public function decodesQuotedPrintableFile(): void
    {
        $body = $this->makeStream(
            "--b\r\n"
            . "Content-Type: text/plain; name=notes.txt\r\n"
            . "Content-Transfer-Encoding: Quoted-Printable\r\n"
            . "Content-Disposition: attachment; filename=notes.txt\r\n"
            . "\r\n"
            . "Gr=C3=BC=C3=9Fe, x = y, a=zz b, a soft= \r\nbreak\r\n"
            . "--b\r\n"
            . "Content-Type: text/html\r\n"
            . "\r\n"
            . "<p>After the file</p>\r\n"
            . "--b--\r\n",
        );

        $result = $this->parse($body, 'b');

        Assert::count($result, 2);
        Assert::instanceOf($result[0], File::class);
        Assert::same($result[0]->getStream()->__toString(), 'Grüße, x = y, a=zz b, a softbreak');
        Assert::instanceOf($result[1], Field::class);
        Assert::same($result[1]->getValue(), '<p>After the file</p>');
    }

    /**
     * The decoding filters hold back the end of the data for the next write: the file ends with it.
     */
    #[DataSet(['quoted-printable', 'hello=3Dworld=A', 'hello=world=A'], 'quoted-printable ending with a partial escape')]
    #[DataSet(['quoted-printable', 'a soft line break at the end=', 'a soft line break at the end'], 'quoted-printable ending with `=`')]
    #[DataSet(['base64', 'QUJDRA', 'ABCD'], 'unpadded base64')]
    #[Test]
    public function fileEndsWithTheDataTheFilterHeldBack(string $encoding, string $encoded, string $decoded): void
    {
        $body = $this->makeStream(
            "--b\r\nContent-Type: application/octet-stream\r\nContent-Transfer-Encoding: {$encoding}\r\n"
            . "Content-Disposition: attachment; filename=data.bin\r\n\r\n{$encoded}\r\n"
            . "--b\r\nContent-Type: text/plain\r\n\r\nafter\r\n--b--\r\n",
        );

        $result = $this->parse($body, 'b');

        Assert::count($result, 2);
        Assert::instanceOf($result[0], File::class);
        Assert::same($result[0]->getStream()->__toString(), $decoded);
        Assert::same($result[0]->getSize(), \strlen($decoded));
        Assert::same($result[1]->getValue(), 'after');
    }

    #[Test]
    public function parsesPartsAfterPreambleWithFoldedHeaders(): void
    {
        $body = $this->makeStream(
            "This is a multi-part message in MIME format.\r\n"
            . "--b\r\n"
            . "Content-Type: multipart/related;\r\n boundary=\"inner\"\r\n"
            . "\r\n"
            . "--inner--\r\n"
            . "--b\r\n"
            . "Content-Type: image/png\r\n"
            . "Content-Disposition: inline;\r\n\tfilename=\"logo.png\"\r\n"
            . "\r\n"
            . "png\r\n"
            . "--b--\r\n",
        );

        $result = $this->parse($body, 'b');

        Assert::count($result, 2);
        Assert::same($result[0]->getHeaders(), ['Content-Type' => ['multipart/related; boundary="inner"']]);
        Assert::instanceOf($result[1], File::class);
        Assert::same($result[1]->getClientFilename(), 'logo.png');
        Assert::same($result[1]->getStream()->__toString(), 'png');
    }

    #[DataSet(['BASE64', 'cGRm'], 'base64 in upper case')]
    #[DataSet([' Base64 ', 'cGRm'], 'base64 in mixed case with spaces')]
    #[DataSet(['quoted-printable', "p=64=\r\nf"], 'quoted-printable')]
    #[DataSet(['Quoted-Printable', "p=64=\r\nf"], 'quoted-printable in mixed case')]
    #[DataSet(['7bit', 'pdf'], '7bit')]
    #[Test]
    public function decodesFileTransferEncodingInAnyCase(string $encoding, string $encoded): void
    {
        $body = $this->makeStream(
            "--b\r\nContent-Type: application/pdf\r\nContent-Transfer-Encoding: {$encoding}\r\n"
            . "Content-Disposition: attachment; filename=a.pdf\r\n\r\n{$encoded}\r\n--b--\r\n",
        );

        $result = $this->parse($body, 'b');

        Assert::count($result, 1);
        Assert::instanceOf($result[0], File::class);
        Assert::same($result[0]->getStream()->__toString(), 'pdf');
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
