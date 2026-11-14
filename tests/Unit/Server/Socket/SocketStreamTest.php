<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Server\Socket;

use Buggregator\Trap\Server\Socket\Client;
use Buggregator\Trap\Server\Socket\Exception\ClientDisconnected;
use Buggregator\Trap\Server\Socket\SocketStream;
use Buggregator\Trap\Support\Activity;
use Buggregator\Trap\Support\Timer;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(SocketStream::class)]
final class SocketStreamTest
{
    private \Socket $server;
    private \Socket $remote;
    private Client $client;
    private \Fiber $clientFiber;
    private SocketStream $stream;

    #[BeforeTest]
    public function connect(): void
    {
        Activity::take();
        $this->server = \socket_create(\AF_INET, \SOCK_STREAM, \SOL_TCP);
        \socket_bind($this->server, '127.0.0.1', 0);
        \socket_listen($this->server);
        \socket_getsockname($this->server, $address, $port);

        $this->remote = \socket_create(\AF_INET, \SOCK_STREAM, \SOL_TCP);
        \socket_connect($this->remote, '127.0.0.1', $port);
        $accepted = \socket_accept($this->server);
        \assert($accepted instanceof \Socket);

        // No select period: the client checks its socket on every resume
        $this->client = Client::init($accepted, selectPeriod: 0.0);
        $this->stream = SocketStream::create($this->client, 7);
        $this->clientFiber = new \Fiber($this->client->process(...));
        $this->clientFiber->start();
    }

    #[AfterTest]
    public function close(): void
    {
        $this->client->destroy();
        \socket_close($this->remote);
        \socket_close($this->server);
        Activity::take();
    }

    public function queuesReceivedPayloads(): void
    {
        $this->deliver('hello');

        Assert::same($this->stream->clientId, 7);
        Assert::true($this->stream->hasData());
        Assert::same($this->stream->getData(), 'hello');
        Assert::false($this->stream->isDisconnected());
        Assert::false($this->stream->isFinished());
    }

    public function fetchLineSplitsChunkByLineEnds(): void
    {
        $this->deliver("first\nsecond\n");

        Assert::same($this->read($this->stream->fetchLine(...)), "first\n");
        Assert::same($this->stream->getData(), "second\n");
        Assert::same($this->read($this->stream->fetchLine(...)), "second\n");
        Assert::false($this->stream->hasData());
    }

    public function fetchLineWaitsForLineEnd(): void
    {
        $this->deliver('par');
        $reader = new \Fiber($this->stream->fetchLine(...));
        $reader->start();

        Assert::true($reader->isSuspended());

        \socket_write($this->remote, "tial\nrest");

        Assert::same($this->read($reader), "partial\n");
        Assert::same($this->stream->getData(), 'rest');
    }

    public function fetchLineReturnsTheRestOnDisconnect(): void
    {
        $this->deliver('tail');
        $this->client->destroy();

        Assert::true($this->stream->isDisconnected());
        Assert::same($this->read($this->stream->fetchLine(...)), 'tail');
        Assert::true($this->stream->isFinished());
    }

    public function fetchAllCollectsPayloadsUntilDisconnect(): void
    {
        $this->deliver('first ');
        $reader = new \Fiber($this->stream->fetchAll(...));
        $reader->start();
        $this->deliver('second');

        $this->client->destroy();

        Assert::same($this->read($reader), 'first second');
    }

    public function sendDataWritesToTheClient(): void
    {
        Assert::false($this->stream->sendData(''));
        Assert::true($this->stream->sendData('pong'));

        $this->clientFiber->resume();

        Assert::same(\socket_read($this->remote, 100), 'pong');
    }

    public function disconnectedStreamSendsNothing(): void
    {
        $this->client->destroy();

        $this->stream->disconnect();

        Assert::false($this->stream->sendData('ping'));
        Assert::true($this->stream->isFinished());
    }

    public function disconnectClosesTheClient(): never
    {
        $this->stream->disconnect();

        Expect::exception(ClientDisconnected::class);
        $this->clientFiber->resume();
    }

    public function waitDataStopsWhenTheTimerIsReady(): void
    {
        $ready = false;
        $timer = new Timer(condition: static function () use (&$ready): bool {
            return $ready;
        });
        $reader = new \Fiber(fn() => $this->stream->waitData($timer));
        $reader->start();
        $reader->resume();
        Assert::true($reader->isSuspended());

        $ready = true;
        $reader->resume();

        Assert::true($reader->isTerminated());
    }

    /**
     * Sends the data to the client and lets it read until the stream has it.
     */
    private function deliver(string $data): void
    {
        $expected = $this->stream->getData() . $data;
        \socket_write($this->remote, $data);
        $deadline = \microtime(true) + 5;
        while ($this->stream->getData() !== $expected && \microtime(true) < $deadline) {
            \usleep(1_000);
            $this->clientFiber->resume();
        }

        Assert::same($this->stream->getData(), $expected);
    }

    /**
     * Runs the reader, letting the client read between its suspensions, and returns its result.
     *
     * @param \Fiber|\Closure(): string $reader
     */
    private function read(\Fiber|\Closure $reader): string
    {
        if ($reader instanceof \Closure) {
            $reader = new \Fiber($reader);
            $reader->start();
        }

        $deadline = \microtime(true) + 5;
        while (!$reader->isTerminated() && \microtime(true) < $deadline) {
            $this->stream->isDisconnected() or $this->clientFiber->resume();
            $reader->resume();
            $reader->isTerminated() or \usleep(1_000);
        }

        Assert::true($reader->isTerminated());
        return $reader->getReturn();
    }
}
