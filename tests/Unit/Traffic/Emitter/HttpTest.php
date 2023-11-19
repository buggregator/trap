<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Traffic\Emitter;

use Buggregator\Trap\Handler\Http\Emitter;
use Buggregator\Trap\Test\Mock\StreamClientMock;
use Buggregator\Trap\Tests\Unit\FiberTrait;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class HttpTest extends TestCase
{
    use FiberTrait;

    /**
     * Test that the same body is emitted to multiple streams at the same time.
     *
     * @covers \Buggregator\Trap\Support\StreamHelper::concurrentReadStream()
     */
    public function testConcurrentBodyReading(): void
    {
        $this->markTestSkipped('This test is not working yet.');
        $content = <<<CONTENT
        0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ
        CONTENT;

        $stream = \fopen('php://memory', 'w+b');
        \fwrite($stream, $content);
        $response = new Response(200, [], $stream);

        $bufferBefore = Emitter::$bufferSize;

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
            Emitter::$bufferSize = 2;
            $this->runInFibers(
                static function () use ($response, $stream1): void {
                    Emitter::emit($stream1, $response);
                },
                static function () use ($response, $stream2): void {
                    Emitter::emit($stream2, $response);
                },
            );
        } finally {
            Emitter::$bufferSize = $bufferBefore;
        }

        // Check that the data is the same
        $this->assertSame($data1, $data2);
        $this->assertSame($content, $data1);
        $this->assertSame($content, $data2);
    }
}
