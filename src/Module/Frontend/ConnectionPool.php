<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Frontend;

use Buggregator\Trap\Module\Frontend\Message\Connect;
use Buggregator\Trap\Module\Frontend\Message\Response;
use Buggregator\Trap\Server\Logger;
use Buggregator\Trap\Server\Processable;
use Buggregator\Trap\Server\Traffic\StreamClient;
use Buggregator\Trap\Server\Traffic\Websocket\Frame;
use Buggregator\Trap\Server\Traffic\Websocket\Opcode;
use Buggregator\Trap\Server\Traffic\Websocket\StreamReader;
use Buggregator\Trap\Support\Json;
use Buggregator\Trap\Support\Timer;
use Buggregator\Trap\Support\Uuid;

/**
 * @internal
 * @implements \IteratorAggregate<StreamClient>
 */
final class ConnectionPool implements \IteratorAggregate, Processable
{
    /** @var StreamClient[] */
    private array $streams = [];

    /** @var \Fiber[] */
    private array $fibers = [];

    /** @var array<int, array<non-empty-string, true>> Channels each stream is subscribed to, by object ID */
    private array $subs = [];

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
                unset($this->streams[$key], $this->subs[\spl_object_id($stream)]);
            }
        });
    }

    #[\Override]
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
    #[\Override]
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

    /**
     * Sends a publication to the connected clients, subscribing them to the channel first when needed:
     * centrifuge-js drops publications of a channel the client is not subscribed to.
     *
     * @param non-empty-string $channel
     */
    public function publish(string $channel, mixed $data): void
    {
        $pub = $this->packPayload(Json::encode(['push' => ['channel' => $channel, 'pub' => ['data' => $data]]]));
        $subscribe = $this->packPayload(Json::encode(['push' => ['channel' => $channel, 'subscribe' => (object) []]]));

        foreach ($this->streams as $stream) {
            $id = \spl_object_id($stream);
            if (!isset($this->subs[$id][$channel])) {
                $this->subs[$id][$channel] = true;
                $stream->sendData($subscribe);
            }
            $stream->sendData($pub);
        }
    }

    /**
     * Channels any connected client is subscribed to by {@see publish()}.
     *
     * @return list<non-empty-string>
     */
    public function channels(): array
    {
        $channels = [];
        foreach ($this->streams as $stream) {
            $channels += $this->subs[\spl_object_id($stream)] ?? [];
        }

        return \array_keys($channels);
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
