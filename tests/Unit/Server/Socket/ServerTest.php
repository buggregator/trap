<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Server\Socket;

use Buggregator\Trap\Server\Logger;
use Buggregator\Trap\Server\Socket\Client;
use Buggregator\Trap\Server\Socket\Exception\ServerStopped;
use Buggregator\Trap\Server\Socket\Server;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Covers(Server::class)]
final class ServerTest
{
    #[Test]
    public function acceptsBeforeAcceptPeriodWhenWoken(): void
    {
        $accepted = 0;
        [$server, $port] = $this->listen(static function (Client $client) use (&$accepted): void {
            ++$accepted;
        });
        $remote = null;

        try {
            // Polls with nothing to accept: the next poll is an hour later
            $server->process();
            $remote = $this->connect($port);
            \usleep(50_000);

            $server->process();
            Assert::same($accepted, 0);

            [$read] = $server->getSockets();
            $server->wake($read);
            $server->process();
            Assert::same($accepted, 1);
        } finally {
            $remote === null or \socket_close($remote);
            $server->destroy();
        }
    }

    #[Test]
    public function listensForClientSockets(): void
    {
        $clients = [];
        [$server, $port] = $this->listen(static function (Client $client) use (&$clients): void {
            $clients[] = $client;
        });
        $remote = $this->connect($port);

        try {
            \usleep(50_000);
            $server->process();
            [$read, $write] = $server->getSockets();

            Assert::same(\count($read), 2);
            Assert::same($read[1], $clients[0]->getSocket());
            Assert::same($write, []);
        } finally {
            \socket_close($remote);
            $server->destroy();
        }
    }

    #[Test]
    public function stopsListeningWhenCancelled(): void
    {
        [$server, $port] = $this->listen();
        $remote = $this->connect($port);

        try {
            $server->cancel();
            [$read] = $server->getSockets();

            Assert::same($read, []);
        } finally {
            \socket_close($remote);
            $server->destroy();
        }
    }

    #[Test]
    public function rejectsUnknownProtocol(): never
    {
        Expect::exception(\RuntimeException::class)->withMessage('Unknown protocol `nope`.');

        Server::init('nope', '127.0.0.1', 1);
    }

    #[Test]
    public function dropsClientWhoseConnectionFailed(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_VERY_VERBOSE);
        $clients = [];
        [$server, $port] = $this->listen(static function (Client $client) use (&$clients): void {
            $clients[] = $client;
        }, new Logger($output));
        $remote = $this->connect($port);

        try {
            \usleep(50_000);
            $server->process();
            Assert::count($clients, 1);

            \socket_close($remote);
            $remote = null;
            \usleep(50_000);
            $server->wake([$clients[0]->getSocket()]);
            $server->process();

            [$read, $write] = $server->getSockets();
            Assert::count($read, 1);
            Assert::same($write, []);
            Assert::string($output->fetch())->contains('Client 1 fiber.');
        } finally {
            $remote === null or \socket_close($remote);
            $server->destroy();
        }
    }

    #[Test]
    public function stopsOnceCancelledClientsDisconnect(): never
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_VERY_VERBOSE);
        [$server, $port] = $this->listen(logger: new Logger($output));
        $remote = $this->connect($port);
        \usleep(50_000);
        $server->process();

        $server->cancel();

        Expect::exception(ServerStopped::class);
        try {
            $server->process();
        } finally {
            Assert::string($output->fetch())->contains('Client 1 disconnected');
            \socket_close($remote);
            $server->destroy();
        }
    }

    #[Test]
    public function dropsClientRejectedByInflector(): void
    {
        [$server, $port] = $this->listen(static function (): never {
            throw new \RuntimeException('Rejected.');
        });
        $remote = $this->connect($port);

        try {
            \usleep(50_000);
            $server->process();

            [$read] = $server->getSockets();
            Assert::count($read, 1);
        } finally {
            \socket_close($remote);
            $server->destroy();
        }
    }

    #[Test]
    public function acceptsTheRestOfManyConnectionsOnNextProcess(): void
    {
        $accepted = 0;
        [$server, $port] = $this->listen(static function () use (&$accepted): void {
            ++$accepted;
        });
        $remotes = [];

        try {
            for ($i = 0; $i < 40; ++$i) {
                $remotes[] = $this->connect($port);
            }
            \usleep(50_000);

            $server->process();
            Assert::same($accepted, 32);

            // The accept period is an hour: only the limit makes the server accept again
            $server->process();
            Assert::same($accepted, 40);
        } finally {
            foreach ($remotes as $remote) {
                \socket_close($remote);
            }
            $server->destroy();
        }
    }

    #[Test]
    public function hasNoSocketsWhenDestroyed(): void
    {
        [$server] = $this->listen();

        $server->destroy();

        Assert::same($server->getSockets(), [[], []]);
    }

    /**
     * @param null|\Closure(Client, int): mixed $inflector
     *
     * @return array{Server, int<1, 65535>}
     */
    private function listen(?\Closure $inflector = null, Logger $logger = new Logger()): array
    {
        $probe = \socket_create(\AF_INET, \SOCK_STREAM, \SOL_TCP);
        \socket_bind($probe, '127.0.0.1', 0);
        \socket_getsockname($probe, $address, $port);
        \socket_close($probe);
        \assert($port > 0 && $port < 65536);

        return [
            Server::init('tcp', '127.0.0.1', $port, acceptPeriod: 3600.0, clientInflector: $inflector, logger: $logger),
            $port,
        ];
    }

    private function connect(int $port): \Socket
    {
        $socket = \socket_create(\AF_INET, \SOCK_STREAM, \SOL_TCP);
        \socket_connect($socket, '127.0.0.1', $port);

        return $socket;
    }
}
