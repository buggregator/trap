<?php

declare(strict_types=1);

namespace Buggregator\Trap\Test\Mock;

use Buggregator\Trap\Support\Timer;
use Buggregator\Trap\Test\Mock\StreamClientMock\DisconnectCommand;
use Buggregator\Trap\Traffic\StreamClient;

/**
 * @internal
 */
final class StreamClientMock implements StreamClient
{
    /** @var \SplQueue<string> */
    private \SplQueue $queue;

    private bool $disconnected = false;

    private function __construct(
        private readonly \Generator $generator,
        private readonly \DateTimeInterface $createdAt = new \DateTimeImmutable(),
    ) {
        $this->queue = new \SplQueue();
    }

    public static function createFromGenerator(\Generator $generator): StreamClient
    {
        return new self($generator);
    }

    public function hasData(): bool
    {
        return !$this->queue->isEmpty();
    }

    public function waitData(?Timer $timer = null): void
    {
        $before = $this->queue->count();
        do {
            \Fiber::suspend();
            $this->fetchFromGenerator();
        } while (!$this->disconnected && $this->queue->count() === $before);
    }

    public function sendData(string $data): bool
    {
        if ($data === '' || $this->isDisconnected()) {
            return false;
        }

        $this->fetchFromGenerator();
        $this->generator->send($data);

        return true;
    }

    public function disconnect(): void
    {
        if ($this->isDisconnected()) {
            return;
        }
        $this->fetchFromGenerator();
        $this->generator->send(new DisconnectCommand());
        $this->disconnected = true;
    }

    public function isDisconnected(): bool
    {
        $this->disconnected = $this->disconnected || !$this->generator->valid();
        return $this->disconnected;
    }

    public function isFinished(): bool
    {
        return $this->isDisconnected() && $this->queue->isEmpty();
    }

    /**
     * Copy of {@see \Buggregator\Trap\Socket\SocketStream::fetchLine()}
     */
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

    public function getIterator(): \Generator
    {
        while (!$this->isDisconnected() || !$this->queue->isEmpty()) {
            if ($this->queue->isEmpty()) {
                $this->fetchFromGenerator();
                \Fiber::suspend();
                continue;
            }
            yield (string) $this->queue->dequeue();
        }
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    private function fetchFromGenerator(): void
    {
        if ($this->isFinished()) {
            return;
        }
        $value = (string) $this->generator->current();

        if ($value !== '') {
            $this->queue->enqueue($value);
        }

        $this->generator->next();
    }
}
