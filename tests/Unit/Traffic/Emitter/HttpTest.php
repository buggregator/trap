<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Traffic\Emitter;

use Buggregator\Trap\Handler\Http\Emitter;
use Buggregator\Trap\Test\Mock\StreamClientMock;
use Buggregator\Trap\Tests\Unit\FiberTrait;
use Nyholm\Psr7\Response;
use Testo\Assert;
use Testo\Skip;
use Testo\Test;

final class HttpTest
{
    use FiberTrait;

    /**
     * Test that the same body is emitted to multiple streams at the same time.
     *
     * @covers \Buggregator\Trap\Support\StreamHelper::concurrentReadStream()
     */
    #[Test]
    #[Skip('This test is not working yet.')]
    public function testConcurrentBodyReading(): void
    {
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
        Assert::same($data2, $data1);
        Assert::same($data1, $content);
        Assert::same($data2, $content);
    }
}
