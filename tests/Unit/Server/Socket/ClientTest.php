<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Server\Socket;

use Buggregator\Trap\Server\Socket\Client;
use Buggregator\Trap\Support\Activity;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Covers(Client::class)]
final class ClientTest
{
    #[BeforeTest]
    #[AfterTest]
    public function forgetActivity(): void
    {
        Activity::take();
    }

    #[Test]
    public function readsAvailableDataInOneIteration(): void
    {
        [$server, $remote, $socket] = $this->connect();
        $client = Client::init($socket, payloadSize: 1024 * 1024);
        $payloads = [];
        $client->setOnPayload(static function (string $payload) use (&$payloads): void {
            $payloads[] = $payload;
        });

        try {
            \socket_write($remote, \str_repeat('a', 1000));
            \socket_write($remote, \str_repeat('b', 1000));
            \usleep(50_000);

            $fiber = new \Fiber($client->process(...));
            $fiber->start();

            Assert::same(\implode('', $payloads), \str_repeat('a', 1000) . \str_repeat('b', 1000));
            Assert::true(Activity::take());
        } finally {
            $client->destroy();
            \socket_close($remote);
            \socket_close($server);
        }
    }

    #[Test]
    public function readsUpToPayloadSizePerIteration(): void
    {
        [$server, $remote, $socket] = $this->connect();
        $client = Client::init($socket, payloadSize: 100);
        $payloads = [];
        $client->setOnPayload(static function (string $payload) use (&$payloads): void {
            $payloads[] = $payload;
        });

        try {
            \socket_write($remote, \str_repeat('a', 250));
            \usleep(50_000);

            $fiber = new \Fiber($client->process(...));
            $fiber->start();
            Assert::same($payloads, [\str_repeat('a', 100)]);

            $fiber->resume();
            $fiber->resume();
            Assert::same($payloads, [\str_repeat('a', 100), \str_repeat('a', 100), \str_repeat('a', 50)]);
        } finally {
            $client->destroy();
            \socket_close($remote);
            \socket_close($server);
        }
    }

    #[Test]
    public function deliversDataBeforeFailingOnClosedConnection(): void
    {
        [$server, $remote, $socket] = $this->connect();
        $client = Client::init($socket);
        $payloads = [];
        $client->setOnPayload(static function (string $payload) use (&$payloads): void {
            $payloads[] = $payload;
        });

        try {
            \socket_write($remote, 'last words');
            \socket_shutdown($remote, 1);
            \usleep(50_000);
            $fiber = new \Fiber($client->process(...));
            $fiber->start();
            Assert::same($payloads, ['last words']);

            Expect::exception(\RuntimeException::class);
            $fiber->resume();
        } finally {
            $client->destroy();
            \socket_close($remote);
            \socket_close($server);
        }
    }

    #[Test]
    public function readsBeforeSelectPeriodWhenWoken(): void
    {
        [$server, $remote, $socket] = $this->connect();
        $client = Client::init($socket, selectPeriod: 3600.0);
        $payloads = [];
        $client->setOnPayload(static function (string $payload) use (&$payloads): void {
            $payloads[] = $payload;
        });

        try {
            $fiber = new \Fiber($client->process(...));
            $fiber->start();
            \socket_write($remote, 'ping');
            \usleep(50_000);

            $fiber->resume();
            Assert::same($payloads, []);

            $client->wake();
            $fiber->resume();
            Assert::same($payloads, ['ping']);
        } finally {
            $client->destroy();
            \socket_close($remote);
            \socket_close($server);
        }
    }

    #[Test]
    public function writesBeforeSelectPeriod(): void
    {
        [$server, $remote, $socket] = $this->connect();
        $client = Client::init($socket, selectPeriod: 3600.0);

        try {
            $fiber = new \Fiber($client->process(...));
            $fiber->start();
            Assert::null($client->writableSocket());

            $client->send('pong');
            Assert::same($client->writableSocket(), $socket);
            $fiber->resume();
            \usleep(50_000);

            Assert::same(\socket_read($remote, 100), 'pong');
            Assert::null($client->writableSocket());
        } finally {
            $client->destroy();
            \socket_close($remote);
            \socket_close($server);
        }
    }

    #[Test]
    public function stopsReadingWhileSendBufferIsFull(): void
    {
        [$server, $remote, $socket] = $this->connect();
        // Windows takes all it is given unless the peer's receive buffer is tiny,
        // and a tiny one on Linux slows the transfer to delayed ACKs
        \socket_set_option($socket, \SOL_SOCKET, \SO_SNDBUF, 4096);
        \PHP_OS_FAMILY === 'Windows' and \socket_set_option($remote, \SOL_SOCKET, \SO_RCVBUF, 4096);
        \socket_set_nonblock($remote);
        $client = Client::init($socket);
        $chunk = \str_repeat('x', 1024 * 1024);

        try {
            // One write pass over more than any kernel takes at once: Windows takes a few MB
            $sent = 0;
            for ($i = 0; $i < 16; ++$i) {
                $client->send($chunk);
                $sent += \strlen($chunk);
            }
            $fiber = new \Fiber($client->process(...));
            $fiber->start();
            // The written part is progress though the fiber waits for the buffer
            Assert::true(Activity::take());
            Assert::null($client->readableSocket());
            Assert::same($client->writableSocket(), $socket);

            // The fiber retries the write on each resume, with no wake-up by the main loop
            $received = 0;
            $deadline = \microtime(true) + 10;
            while ($received < $sent && \microtime(true) < $deadline) {
                $data = @\socket_read($remote, 1024 * 1024);
                $received += \is_string($data) ? \strlen($data) : 0;
                $fiber->resume();
            }

            Assert::same($received, $sent);
            Assert::same($client->readableSocket(), $socket);
        } finally {
            $client->destroy();
            \socket_close($remote);
            \socket_close($server);
        }
    }

    /**
     * @return array{\Socket, \Socket, \Socket} Listening socket, remote socket, accepted socket.
     */
    private function connect(): array
    {
        $server = \socket_create(\AF_INET, \SOCK_STREAM, \SOL_TCP);
        \socket_bind($server, '127.0.0.1', 0);
        \socket_listen($server);
        \socket_getsockname($server, $address, $port);

        $remote = \socket_create(\AF_INET, \SOCK_STREAM, \SOL_TCP);
        \socket_connect($remote, '127.0.0.1', $port);
        $accepted = \socket_accept($server);
        \assert($accepted instanceof \Socket);

        return [$server, $remote, $accepted];
    }
}
