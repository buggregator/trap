<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Server\Traffic\Emitter;

use Buggregator\Trap\Server\Handler\Http\Emitter;
use Buggregator\Trap\Support\Stream\ConcurrentReadStream;
use Buggregator\Trap\Test\Mock\StreamClientMock;
use Buggregator\Trap\Test\Mock\StreamClientMock\DisconnectCommand;
use Buggregator\Trap\Tests\Unit\FiberTrait;
use Nyholm\Psr7\Response;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Covers(Emitter::class)]
#[Covers(ConcurrentReadStream::class)]
final class HttpTest
{
    use FiberTrait;

    /**
     * Test that the same body is emitted to multiple streams at the same time.
     */
    #[Test]
    public function testConcurrentBodyReading(): void
    {
        $content = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

        $stream = \fopen('php://memory', 'w+b');
        \fwrite($stream, $content);
        $response = new Response(200, [], $stream);

        $collect = static function (string &$emittedData): \Generator {
            while (!($data = yield '') instanceof DisconnectCommand) {
                $emittedData .= $data;
            }
        };

        $data1 = $data2 = '';
        $client1 = StreamClientMock::createFromGenerator($collect($data1));
        $client2 = StreamClientMock::createFromGenerator($collect($data2));

        $bufferBefore = Emitter::$bufferSize;
        try {
            Emitter::$bufferSize = 2;
            $this->runInFibers(
                static function () use ($response, $client1): void {
                    Emitter::emit($client1, $response);
                    $client1->disconnect();
                },
                static function () use ($response, $client2): void {
                    Emitter::emit($client2, $response);
                    $client2->disconnect();
                },
            );
        } finally {
            Emitter::$bufferSize = $bufferBefore;
        }

        Assert::same($data1, "HTTP/1.1 200 OK\r\nContent-Length: 62\r\n\r\n{$content}");
        Assert::same($data2, $data1);
    }
}
