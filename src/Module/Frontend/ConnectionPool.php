<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Frontend;

use Buggregator\Trap\Module\Frontend\Message\Connect;
use Buggregator\Trap\Module\Frontend\Message\Response;
use Buggregator\Trap\Server\Event\Event;
use Buggregator\Trap\Server\Event\EventStore;
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
 * WebSocket clients of the UI, speaking the Centrifugo protocol of centrifuge-js.
 *
 * A client is subscribed to channels by the server: to the project channels of {@see Publisher} at connect,
 * and to a new channel before its first publication. centrifuge-js drops a publication of a channel
 * it is not subscribed to.
 *
 * @internal
 * @implements \IteratorAggregate<StreamClient>
 */
final class ConnectionPool implements \IteratorAggregate, Processable
{
    /** @var StreamClient[] */
    private array $streams = [];

    /**
     * Channels of each connected client; a client is here after its `connect` command.
     * @var array<int, array<non-empty-string, true>>
     */
    private array $subs = [];

    /** @var \Fiber[] */
    private array $fibers = [];

    /** @var array<int, array<non-empty-string, true>> Channels each stream is subscribed to, by object ID */
    private array $subs = [];

    public function __construct(
        private readonly Logger $logger,
        private RPC $rpc,
        private readonly EventStore $events,
    ) {}

    public function addStream(StreamClient $stream): void
    {
        $key = (int) \array_key_last($this->streams) + 1;
        $this->streams[$key] = $stream;
        $this->fibers[] = new \Fiber(function () use ($key, $stream): void {
            try {
                $this->processSocket($key, $stream);
            } finally {
                unset($this->streams[$key], $this->subs[$key]);
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

    /**
     * Sends a publication to the connected clients, subscribing them to the channel first when needed.
     *
     * @param non-empty-string $channel
     */
    public function publish(string $channel, mixed $data): void
    {
        $pub = $this->packPayload(Json::encode(['push' => ['channel' => $channel, 'pub' => ['data' => $data]]]));
        $subscribe = $this->packPayload(Json::encode(['push' => ['channel' => $channel, 'subscribe' => (object) []]]));

        foreach ($this->subs as $key => $channels) {
            $stream = $this->streams[$key] ?? null;
            if ($stream === null) {
                continue;
            }

            if (!isset($channels[$channel])) {
                $this->subs[$key][$channel] = true;
                $stream->sendData($subscribe);
            }

            $stream->sendData($pub);
        }
    }

    /**
     * Channels any connected client is subscribed to.
     *
     * @return list<non-empty-string>
     */
    public function channels(): array
    {
        $channels = [];
        foreach ($this->subs as $subs) {
            $channels += $subs;
        }

        return \array_keys($channels);
    }

    /**
     * @return array<non-empty-string, true> The channels of the default project and of the stored events.
     */
    private function initialChannels(): array
    {
        $channels = [Publisher::projectChannel(Event::DEFAULT_PROJECT) => true];
        foreach ($this->events as $event) {
            $channels[Publisher::projectChannel($event->projectKey())] = true;
        }

        return $channels;
    }

    private function processSocket(int $key, StreamClient $stream): void
    {
        $pingTimer = null;

        foreach (StreamReader::readFrames($stream->getIterator()) as $frame) {
            // Connection close
            if ($frame->opcode === Opcode::Close) {
                break;
            }

            // Ping-pong
            $frame->opcode === Opcode::Ping and $stream->sendData((string) Frame::pong($frame->content));

            // Pong using `{}` message
            if ($frame->content === '{}') {
                continue;
            }

            // Message must be JSON only
            $payload = Json::decode($frame->content);
            if (!\is_array($payload)) {
                continue;
            }

            $response = new Response(\is_numeric($payload['id'] ?? null) ? (int) $payload['id'] : 0);

            // On connected start periodic ping using `{}` message.
            // A token of the connect is ignored: the UI asks for none.
            if (isset($payload['connect'])) {
                $this->subs[$key] = $this->initialChannels();
                $response->connect = new Connect(Uuid::uuid4(), \array_keys($this->subs[$key]));

                if ($pingTimer === null) {
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
