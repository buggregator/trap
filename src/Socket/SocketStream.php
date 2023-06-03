<?php

declare(strict_types=1);

namespace Buggregator\Client\Socket;

use Buggregator\Client\Traffic\StreamClient;
use Fiber;
use Generator;
use IteratorAggregate;

/**
 * Simple abstraction over {@see Client} to make it easier to work with.
 * Use {@see Server::$clientInflector} to wrap {@see Client} into {@see self}.
 */
final class SocketStream implements IteratorAggregate, StreamClient
{
    /** @var \SplQueue<string> */
    private \SplQueue $queue;
    private bool $disconnected = false;

    private function __construct(
        private readonly Client $client,
        public readonly int $clientId,
    ) {
        $this->queue = new \SplQueue();
    }

    public static function create(Client $client, int $id): self
    {
        $self = new self($client, $id);
        $client->setOnPayload(function (string $payload) use ($self): void {
            $self->queue->enqueue($payload);
        });
        $client->setOnClose(function () use ($self): void {
            $self->disconnected = true;
        });

        return $self;
    }

    public function hasData(): bool
    {
        return !$this->queue->isEmpty();
    }

    public function waitData(): void
    {
        $before = $this->queue->count();
        do {
            Fiber::suspend();
        } while (!$this->disconnected && $this->queue->count() === $before);
    }

    public function sendData(string $data): bool
    {
        if ($data === '' || $this->isDisconnected()) {
            return false;
        }

        $this->client->send($data);
        return true;
    }

    public function disconnect(): void
    {
        if ($this->isDisconnected()) {
            return;
        }
        $this->client->disconnect();
    }

    public function isDisconnected(): bool
    {
        return $this->disconnected;
    }

    public function isFinished(): bool
    {
        return $this->disconnected && $this->queue->isEmpty();
    }

    public function fetchLine(): string
    {
        $line = '';

        while (!$this->isFinished()) {
            while (!$this->queue->isEmpty() && !\str_contains($this->queue[0], "\n")) {
                $line .= $this->queue->dequeue();
                $this->waitData();
            }

            // Split chunk by EOL
            if (!$this->queue->isEmpty()) {
                $split = \explode("\n", $this->queue[0], 2);
                $line .= $split[0] . "\n";

                if (!isset($split[1]) || $split[1] === '') {
                    $this->queue->dequeue();
                } else {
                    $this->queue[0] = $split[1];
                }
                break;
            }

            $this->waitData();
        }

        return $line;
    }

    public function fetchAll(): string
    {
        return \implode('', \iterator_to_array($this->getIterator()));
    }

    public function getData(): string
    {
        return \implode('', [...$this->queue]);
    }

    public function getIterator(): Generator
    {
        while (!$this->disconnected || !$this->queue->isEmpty()) {
            if ($this->queue->isEmpty()) {
                Fiber::suspend();
                continue;
            }
            yield $this->queue->dequeue();
        }
    }
}
