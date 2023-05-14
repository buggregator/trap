<?php

declare(strict_types=1);

namespace Buggregator\Client\Socket;

use Buggregator\Client\Logger;
use Fiber;
use Generator;
use IteratorAggregate;

/**
 * Simple abstraction over {@see Client} to make it easier to work with.
 * Use {@see Server::$clientInflector} to wrap {@see Client} into {@see self}.
 */
class StreamClient implements IteratorAggregate
{
    /** @var \SplQueue<string> */
    private \SplQueue $queue;
    private bool $disconnected = false;
    private \Closure $dataWriter;

    private function __construct(
        private readonly int $clientId,
    ) {
        $this->queue = new \SplQueue();
    }

    public static function create(Client $client, int $id): self
    {
        $self = new self($id);
        $client->setOnPayload(function (string $payload) use ($self): void {
            $self->queue->enqueue($payload);
        });
        $client->setOnClose(function () use ($self): void {
            $self->disconnected = true;
        });
        $self->dataWriter = static function (string $data) use ($client): void {
            if ($data === '') {
                return;
            }
            $client->send($data);
        };

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
        if ($this->isDisconnected()) {
            return false;
        }
        ($this->dataWriter)($data);
        return true;
    }

    /**
     * @return bool Return {@see true} if stream was closed.
     */
    public function isDisconnected(): bool
    {
        return $this->disconnected;
    }
    /**
     * @return bool Return {@see true} if there will be no more data.
     */
    public function isFinished(): bool
    {
        return $this->disconnected && $this->queue->isEmpty();
    }

    /**
     * Returns {@see string} with trailing EOL or without it if stream was closed.
     * Uses {@see Fiber} to wait for EOL|EOF.
     *
     * todo: collect buffer until EOL|EOF and slice by EOL
     */
    public function fetchLine(): string
    {
        $line = '';

        while (!$this->isFinished()) {
            while (!$this->queue->isEmpty() && !\str_contains($this->queue[0], "\n")) {
                $line .= $this->queue->dequeue();
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

    /**
     * Uses {@see Fiber} to wait for all data.
     */
    public function fetchAll(): string
    {
        return \implode('', \iterator_to_array($this->getIterator()));
    }

    /**
     * Get read data without waiting and without cleaning.
     */
    public function getData(): string
    {
        return \implode('', [...$this->queue]);
    }

    /**
     * Iterate all data by read chunks using {@see Generator} until {@see self::isDisconnected()}.
     * Cleans cache.
     * Uses {@see Fiber} to wait for all data.
     *
     * @return Generator<int, string, mixed, void>
     */
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
