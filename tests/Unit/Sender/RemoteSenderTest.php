<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Sender;

use Buggregator\Trap\Proto\Frame;
use Buggregator\Trap\Sender\RemoteSender;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RemoteSender::class)]
final class RemoteSenderTest extends TestCase
{
    private const UUID = '018f3f6a-7b1c-4d2e-9f3a-5b6c7d8e9f00';

    /** @var resource */
    private $server;

    public function testSendsOnlyNonOtlpFrames(): void
    {
        $sender = $this->createSender();

        $sender->send([
            new Frame\Monolog(['message' => 'first']),
            new Frame\Otlp\OtlpTraces([]),
            new Frame\Monolog(['message' => 'second']),
        ]);
        $package = $this->receive($sender);

        self::assertStringStartsWith('1|', $package);
        [, , $uuid, $payload] = \explode('|', \rtrim($package, "\n"), 4);
        self::assertSame(self::UUID, $uuid);
        $frames = \json_decode($payload, true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(['monolog', 'monolog'], \array_column($frames, 'type'));
    }

    public function testSendsNothingForOnlyOtlpFrames(): void
    {
        $sender = $this->createSender();

        $sender->send((static function (): \Generator {
            yield new Frame\Otlp\OtlpTraces([]);
        })());
        $sender->send([new Frame\Monolog(['message' => 'after'])]);
        $package = $this->receive($sender);

        // The first package on the wire is the one sent after the OTLP-only batch.
        $frames = \json_decode(\explode('|', \rtrim($package, "\n"), 4)[3], true, 512, \JSON_THROW_ON_ERROR);
        self::assertCount(1, $frames);
        self::assertSame('monolog', $frames[0]['type']);
        self::assertSame(1, \substr_count($package, "\n"));
    }

    protected function tearDown(): void
    {
        isset($this->server) and \fclose($this->server);
    }

    private function createSender(): RemoteSender
    {
        $server = \stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $server !== false or self::fail("Cannot listen: $error");
        $this->server = $server;
        $port = (int) \substr((string) \strrchr((string) \stream_socket_get_name($server, false), ':'), 1);

        return new RemoteSender(self::UUID, '127.0.0.1', $port);
    }

    private function receive(RemoteSender $sender): string
    {
        $sender->process();
        $client = \stream_socket_accept($this->server, 5);
        $client !== false or self::fail('The sender did not connect.');
        \stream_set_blocking($client, false);

        $data = '';
        $deadline = \microtime(true) + 5;
        while (!\str_ends_with($data, "\n") && \microtime(true) < $deadline) {
            $sender->process();
            $data .= (string) \fread($client, 65536);
        }
        \fclose($client);

        return $data;
    }
}
