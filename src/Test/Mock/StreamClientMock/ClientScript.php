<?php

declare(strict_types=1);

namespace Buggregator\Trap\Test\Mock\StreamClientMock;

/**
 * A client for {@see \Buggregator\Trap\Test\Mock\StreamClientMock} that behaves like a real one: it sends each
 * packet as a separate read, waits for the server replies it expects, and keeps the connection open while it
 * has nothing to send. Code that waits for more data than the client sends is suspended forever instead of
 * finishing at the end of the data.
 *
 * Steps may be added while the script runs.
 *
 * @internal
 */
final class ClientScript
{
    /** Everything the server has sent */
    public string $received = '';

    /** @var \SplQueue<array{'send'|'wait', string}|array{'close'}> */
    private \SplQueue $steps;

    /** @var int<0, max> Length of {@see self::$received} that a waited reply was already found in */
    private int $consumed = 0;

    public function __construct()
    {
        $this->steps = new \SplQueue();
    }

    /**
     * @param string ...$packets Each one is a separate read for the server.
     */
    public function send(string ...$packets): self
    {
        foreach ($packets as $packet) {
            $this->steps->enqueue(['send', $packet]);
        }

        return $this;
    }

    /**
     * The client sends nothing more until the server sends the reply.
     *
     * @param non-empty-string $reply A part of the reply, such as its code.
     */
    public function waitFor(string $reply): self
    {
        $this->steps->enqueue(['wait', $reply]);
        return $this;
    }

    /**
     * The client closes the connection.
     */
    public function close(): self
    {
        $this->steps->enqueue(['close']);
        return $this;
    }

    /**
     * Whether the client has sent everything and got every reply it waits for.
     */
    public function isDone(): bool
    {
        return $this->steps->isEmpty();
    }

    /**
     * @return \Generator<int, string, mixed, void>
     */
    public function generator(): \Generator
    {
        while (true) {
            $packet = '';
            if (!$this->steps->isEmpty()) {
                $step = $this->steps->bottom();
                if ($step[0] === 'close') {
                    return;
                }

                if ($step[0] === 'send') {
                    $this->steps->dequeue();
                    $packet = $step[1];
                } elseif (false !== $pos = \strpos($this->received, $step[1], $this->consumed)) {
                    $this->steps->dequeue();
                    $this->consumed = $pos + \strlen($step[1]);
                    continue;
                }
            }

            $sent = yield $packet;
            if ($sent instanceof DisconnectCommand) {
                return;
            }

            $this->received .= (string) $sent;
        }
    }
}
