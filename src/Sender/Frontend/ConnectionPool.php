<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender\Frontend;

use Buggregator\Trap\Logger;
use Buggregator\Trap\Processable;
use Buggregator\Trap\Sender\Frontend\Message\Connect;
use Buggregator\Trap\Sender\Frontend\Message\Response;
use Buggregator\Trap\Support\Json;
use Buggregator\Trap\Support\Timer;
use Buggregator\Trap\Support\Uuid;
use Buggregator\Trap\Traffic\StreamClient;
use Buggregator\Trap\Traffic\Websocket\Frame;
use Buggregator\Trap\Traffic\Websocket\Opcode;
use Buggregator\Trap\Traffic\Websocket\StreamReader;
use IteratorAggregate;

/**
 * @internal
 * @implements IteratorAggregate<StreamClient>
 */
final class ConnectionPool implements \IteratorAggregate, Processable
{
    /** @var StreamClient[] */
    private array $streams = [];

    /** @var \Fiber[] */
    private array $fibers = [];

    public function __construct(
        private readonly Logger $logger,
        private RPC $rpc,
    ) {}

    public function addStream(StreamClient $stream): void
    {
        $key = (int) \array_key_last($this->streams) + 1;
        $this->streams[$key] = $stream;
        $this->fibers[] = new \Fiber(function () use ($key, $stream): void {
            try {
                $this->processSocket($stream);
            } finally {
                unset($this->streams[$key]);
            }
        });
    }

    public function process(): void
    {
        foreach ($this->fibers as $key => $fiber) {
            try {
                $fiber->isStarted() ? $fiber->resume() : $fiber->start();

                if ($fiber->isTerminated()) {
                    unset($this->fibers[$key]);
                }
            } catch (\Throwable $e) {
                $this->logger->exception($e);
                unset($this->fibers[$key]);
            }
        }
    }

    /**
     * @return \Traversable<StreamClient>
     */
    public function getIterator(): \Traversable
    {
        foreach ($this->streams as $stream) {
            yield $stream;
        }
    }

    public function send(Frame $frame): void
    {
        $data = (string) $frame;
        foreach ($this->streams as $stream) {
            $stream->sendData($data);
        }
    }

    private function processSocket(StreamClient $stream): void
    {
        $pingTimer = null;
        $lastPong = new \DateTimeImmutable();

        foreach (StreamReader::readFrames($stream->getIterator()) as $frame) {
            // Connection close
            if ($frame->opcode === Opcode::Close) {
                break;
            }

            // Ping-pong
            $frame->opcode === Opcode::Ping and $stream->sendData((string) Frame::pong($frame->content));

            // Pong using `{}` message
            if ($frame->content === '{}') {
                $lastPong = new \DateTimeImmutable();
                continue;
            }

            // Message must be JSON only
            $payload = Json::decode($frame->content);
            if (!\is_array($payload)) {
                continue;
            }

            $response = new Response(\is_numeric($payload['id'] ?? null) ? (int) $payload['id'] : 0);

            // On connected start periodic ping using `{}` message
            if (isset($payload['connect'])) {
                $response->connect = new Connect(Uuid::uuid4());

                $pingTimer = new Timer($response->connect->ping);
                $this->fibers[] = new \Fiber(
                    function () use ($stream, $pingTimer): void {
                        while ($pingTimer->wait() && !$stream->isDisconnected()) {
                            $stream->sendData($this->packPayload('{}'));
                            $pingTimer->reset();
                        }
                    },
                );
            }

            // RPC
            if (isset($payload['rpc'])) {
                $response->rpc = $this->rpc->handleMessage($payload['rpc']);
            }

            $stream->sendData($this->packPayload(Json::encode($response)));
            // Reset ping timer on any message
            $pingTimer?->reset();
        }
    }

    private function packPayload(string|\JsonSerializable $payload): string
    {
        return Frame::text(\is_string($payload) ? $payload : Json::encode($payload))->__toString();
    }
}
