<?php

declare(strict_types=1);

namespace Buggregator\Trap\Test\Mock;

use Buggregator\Trap\Server\Traffic\StreamClient;
use Buggregator\Trap\Support\Timer;
use Buggregator\Trap\Test\Mock\StreamClientMock\DisconnectCommand;

/**
 * @internal
 */
final class StreamClientMock implements StreamClient
{
    /** @var \SplQueue<string> */
    private \SplQueue $queue;

    private string $sendBuffer = '';
    private bool $disconnected = false;

    /** The stream has switched to TLS */
    private bool $tlsStarted = false;

    /** The first queued value is the rest of the chunk the last fetched line came in */
    private bool $leftover = false;

    private function __construct(
        private readonly \Generator $generator,
        private readonly float $silence,
        private readonly bool $tls,
        private readonly \DateTimeInterface $createdAt = new \DateTimeImmutable(),
    ) {
        $this->queue = new \SplQueue();
        // init the Generator
        $value = (string) $this->generator->current();
        $value === '' or $this->queue->enqueue($value);
    }

    /**
     * @param float $silence What {@see self::silence()} reports: a fixed value keeps tests independent of
     *        how long they run.
     * @param bool $tls Whether the server has TLS on: the stream can switch to it.
     */
    public static function createFromGenerator(
        \Generator $generator,
        float $silence = 0.0,
        bool $tls = false,
    ): self {
        return new self($generator, $silence, $tls);
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
        $this->sendBuffer .= $data;

        return true;
    }

    public function canStartTls(): bool
    {
        return $this->tls && !$this->tlsStarted && !$this->isDisconnected();
    }

    public function startTls(): void
    {
        $this->canStartTls() or throw new \LogicException('TLS is unavailable on the stream.');
        // The rest of the chunk the last line came in was sent in plain text. The generator's next values
        // stand for what comes over TLS, though the stream may have taken them already.
        $this->leftover and $this->queue->dequeue();
        $this->leftover = false;
        $this->tlsStarted = true;
    }

    public function isTlsStarted(): bool
    {
        return $this->tlsStarted;
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
     * Copy of {@see \Buggregator\Trap\Server\Socket\SocketStream::fetchLine()}
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

                $this->leftover = isset($split[1]) && $split[1] !== '';
                if ($this->leftover) {
                    $this->queue[0] = $split[1];
                } else {
                    $this->queue->dequeue();
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

    public function silence(): float
    {
        return $this->silence;
    }

    private function fetchFromGenerator(): void
    {
        if ($this->isFinished()) {
            return;
        }
        [$toSend, $this->sendBuffer] = [$this->sendBuffer, ''];
        $value = (string) $this->generator->send($toSend);
        $value === '' or $this->queue->enqueue($value);
    }
}
