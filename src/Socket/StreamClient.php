<?php

declare(strict_types=1);

namespace Buggregator\Client\Socket;

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
    private bool $finished = false;

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
            $self->finished = true;
        });

        return $self;
    }

    public function hasData(): bool
    {
        return !$this->queue->isEmpty();
    }

    public function isFinished(): bool
    {
        return $this->finished;
    }

    /**
     * Returns {@see string} with trailing EOL or without it if stream was closed.
     * Uses {@see Fiber} to wait for EOL|EOF.
     *
     * todo: collect buffer until EOL|EOF when Client has {@see Client::isBinary()} === {@see true}.
     */
    public function fetchLine(): string
    {
        while (!$this->finished && $this->queue->isEmpty()) {
            Fiber::suspend();
        }

        return $this->queue->dequeue();
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
     * Iterate all data using {@see Generator} until {@see self::isFinished()}.
     * Cleans cache.
     * Uses {@see Fiber} to wait for all data.
     *
     * @return Generator<int, string, mixed, void>
     */
    public function getIterator(): Generator
    {
        while (!$this->finished || !$this->queue->isEmpty()) {
            if ($this->queue->isEmpty()) {
                Fiber::suspend();
                continue;
            }

            yield $this->queue->dequeue();
        }
    }
}
