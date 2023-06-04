<?php

declare(strict_types=1);

namespace Buggregator\Client\Tests\Traffic\Emitter;

use Buggregator\Client\Test\Mock\StreamClientMock;
use Buggregator\Client\Tests\FiberTrait;
use Buggregator\Client\Traffic\Emitter\Http;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class HttpTest extends TestCase
{
    use FiberTrait;

    /**
     * Test that the same body is emitted to multiple streams at the same time.
     *
     * @covers \Buggregator\Client\Support\StreamHelper::concurrentReadStream()
     */
    public function testConcurrentBodyReading(): void
    {
        $content = <<<CONTENT
        0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ
        CONTENT;

        $stream = \fopen('php://memory', 'w+b');
        \fwrite($stream, $content);
        $response = new Response(200, [], $stream);

        $bufferBefore = Http::$bufferSize;

        $function = static function (string &$emittedData): \Generator {
            $i = 1;
            while (++$i < 100) {
                $emittedData .= yield '';
            }
        };

        // Create two streams with the same data but different output buffers
        $data1 = $data2 = '';
        $stream1 = StreamClientMock::createFromGenerator($function($data1));
        $stream2 = StreamClientMock::createFromGenerator($function($data2));

        try {
            Http::$bufferSize = 2;
            $this->runInFibers(
                static function () use ($response, $stream1): void {
                    Http::emit($stream1, $response);
                },
                static function () use ($response, $stream2): void {
                    Http::emit($stream2, $response);
                },
            );
        } finally {
            Http::$bufferSize = $bufferBefore;
        }

        // Check that the data is the same
        $this->assertSame($data1, $data2);
        $this->assertSame($content, $data1);
        $this->assertSame($content, $data2);
    }
}
