<?php

declare(strict_types=1);

namespace Buggregator\Client\Tests\Traffic\Http;

use Buggregator\Client\Traffic\Http\HttpParser;
use Buggregator\Client\Traffic\Multipart\Field;
use Buggregator\Client\Traffic\Multipart\Part;
use Fiber;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;

class MultipartBodyParserTest extends TestCase
{
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
        $fiber = new Fiber(fn() => HttpParser::parseMultipartBody($body, $boundary));
        $fiber->start();
        do {
            if ($fiber->isTerminated()) {
                return $fiber->getReturn();
            }
            $fiber->resume();
        } while ($fiber->isSuspended());

        throw new \RuntimeException('Fiber failed');
    }
}
