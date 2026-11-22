<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Socket;

use Buggregator\Trap\Server\Traffic\FetchLineTrait;
use Buggregator\Trap\Server\Traffic\StreamClient;
use Buggregator\Trap\Support\Timer;

/**
 * Simple abstraction over {@see Client} to make it easier to work with.
 * Use {@see Server::$clientInflector} to wrap {@see Client} into {@see self}.
 *
 * @internal
 * @implements \IteratorAggregate<int, string>
 */
final class SocketStream implements \IteratorAggregate, StreamClient
{
    use FetchLineTrait;

    /** The IP address of the peer, as `127.0.0.1` or `::1`; null when the socket doesn't tell */
    public readonly ?string $peerAddress;

    /** @var \SplQueue<string> */
    private \SplQueue $queue;

    private bool $disconnected = false;
    private readonly \DateTimeImmutable $createdAt;

    private function __construct(
        private Client $client,
        public readonly int $clientId,
    ) {
        $this->queue = new \SplQueue();
        $this->createdAt = new \DateTimeImmutable();
        $this->peerAddress = @\socket_getpeername($client->getSocket(), $address) ? $address : null;
    }

    public static function create(Client $client, int $id): self
    {
        $self = new self($client, $id);
        $client->setOnPayload(static function (string $payload) use ($self): void {
            $self->queue->enqueue($payload);
        });
        $client->setOnClose(static function () use ($self): void {
            $self->disconnected = true;
            unset($self->client);
        });

        return $self;
    }

    #[\Override]
    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    #[\Override]
    public function silence(): float
    {
        /** @psalm-suppress RedundantCondition, TypeDoesNotContainType The client is unset once it closes */
        $checkedAt = isset($this->client) ? $this->client->checkedAt() : \microtime(true);

        return \max(0.0, $checkedAt - (float) $this->createdAt->format('U.u'));
    }

    #[\Override]
    public function hasData(): bool
    {
        return !$this->queue->isEmpty();
    }

    #[\Override]
    public function waitData(?Timer $timer = null): void
    {
        $before = $this->queue->count();
        do {
            \Fiber::suspend();
        } while (!$this->disconnected && $this->queue->count() === $before && $timer?->isReady() !== true);
    }

    #[\Override]
    public function sendData(string $data): bool
    {
        if ($data === '' || $this->isDisconnected()) {
            return false;
        }

        $this->client->send($data);
        return true;
    }

    #[\Override]
    public function disconnect(): void
    {
        if ($this->isDisconnected()) {
            return;
        }
        $this->client->disconnect();
    }

    #[\Override]
    public function canStartTls(): bool
    {
        return !$this->isDisconnected() && $this->client->canStartTls();
    }

    #[\Override]
    public function startTls(): void
    {
        $this->canStartTls() or throw new \LogicException('TLS is unavailable on the stream.');
        // Data read in plain text after the request for TLS would pass for data sent over TLS
        $this->queue = new \SplQueue();
        $this->client->startTls();
    }

    #[\Override]
    public function isDisconnected(): bool
    {
        return $this->disconnected;
    }

    #[\Override]
    public function isFinished(): bool
    {
        return $this->disconnected && $this->queue->isEmpty();
    }

    #[\Override]
    public function fetchAll(): string
    {
        return \implode('', \iterator_to_array($this->getIterator()));
    }

    #[\Override]
    public function getData(): string
    {
        return \implode('', [...$this->queue]);
    }

    #[\Override]
    public function getIterator(): \Generator
    {
        while (!$this->disconnected || !$this->queue->isEmpty()) {
            if ($this->queue->isEmpty()) {
                \Fiber::suspend();
                continue;
            }
            yield $this->queue->dequeue();
        }
    }
}
