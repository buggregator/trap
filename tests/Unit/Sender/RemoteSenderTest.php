<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Sender;

use Buggregator\Trap\Proto\Frame;
use Buggregator\Trap\Sender\RemoteSender;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Test;

#[Covers(RemoteSender::class)]
#[Test]
final class RemoteSenderTest
{
    private const UUID = '018f3f6a-7b1c-4d2e-9f3a-5b6c7d8e9f00';

    /** @var resource|null */
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

        Assert::string($package)->startsWith('1|');
        [, , $uuid, $payload] = \explode('|', \rtrim($package, "\n"), 4);
        Assert::same($uuid, self::UUID);
        $frames = \json_decode($payload, true, 512, \JSON_THROW_ON_ERROR);
        Assert::same(\array_column($frames, 'type'), ['monolog', 'monolog']);
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
        Assert::count($frames, 1);
        Assert::same($frames[0]['type'], 'monolog');
        Assert::same(\substr_count($package, "\n"), 1);
    }

    #[AfterTest]
    protected function tearDown(): void
    {
        isset($this->server) and \fclose($this->server);
        $this->server = null;
    }

    private function createSender(): RemoteSender
    {
        $server = \stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $server !== false or Assert::fail("Cannot listen: $error");
        $this->server = $server;
        $port = (int) \substr((string) \strrchr((string) \stream_socket_get_name($server, false), ':'), 1);

        return new RemoteSender(self::UUID, '127.0.0.1', $port);
    }

    private function receive(RemoteSender $sender): string
    {
        $sender->process();
        $client = \stream_socket_accept($this->server, 5);
        $client !== false or Assert::fail('The sender did not connect.');
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
