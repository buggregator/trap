<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Support;

use Buggregator\Trap\Support\LoopPacer;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Covers(LoopPacer::class)]
final class LoopPacerTest
{
    #[Test]
    public function doesNotWaitAfterActivity(): void
    {
        $pacer = new LoopPacer(idleTimeout: 1_000_000, grace: 0);
        $asked = 0;

        $ready = $pacer->pause(true, static function () use (&$asked): array {
            ++$asked;
            return [[], []];
        });

        Assert::same($ready, []);
        Assert::same($asked, 0);
    }

    #[Test]
    public function runsGraceIterationsAfterActivity(): void
    {
        $pacer = new LoopPacer(idleTimeout: 1_000, grace: 2);
        $asked = 0;
        $sockets = static function () use (&$asked): array {
            ++$asked;
            return [[], []];
        };

        $pacer->pause(true, $sockets);
        $pacer->pause(false, $sockets);
        $pacer->pause(false, $sockets);
        Assert::same($asked, 0);

        $pacer->pause(false, $sockets);
        Assert::same($asked, 1);
    }

    #[Test]
    public function runsOneIterationAfterActivityByDefault(): void
    {
        $pacer = new LoopPacer(idleTimeout: 1_000);
        $asked = 0;
        $sockets = static function () use (&$asked): array {
            ++$asked;
            return [[], []];
        };

        $pacer->pause(true, $sockets);
        $pacer->pause(false, $sockets);
        Assert::same($asked, 0);

        $pacer->pause(false, $sockets);
        Assert::same($asked, 1);
    }

    #[Test]
    public function waitsForTimeoutWithoutData(): void
    {
        [$server, $client, $peer] = $this->connect();
        $pacer = new LoopPacer(idleTimeout: 50_000, grace: 0);

        try {
            $start = \hrtime(true);
            $ready = $pacer->pause(false, static fn(): array => [[$server, $peer], []]);
            $elapsed = (\hrtime(true) - $start) / 1e6;
        } finally {
            \socket_close($client);
            \socket_close($peer);
            \socket_close($server);
        }

        Assert::same($ready, []);
        Assert::true($elapsed >= 40, "Waited {$elapsed} ms.");
    }

    #[Test]
    public function wakesUpOnSocketData(): void
    {
        [$server, $client, $peer] = $this->connect();
        $pacer = new LoopPacer(idleTimeout: 5_000_000, grace: 0);

        try {
            \socket_write($client, 'ping');
            $start = \hrtime(true);
            $ready = $pacer->pause(false, static fn(): array => [[$server, $peer], []]);
            $elapsed = (\hrtime(true) - $start) / 1e6;
        } finally {
            \socket_close($client);
            \socket_close($peer);
            \socket_close($server);
        }

        Assert::same($ready, [$peer]);
        Assert::true($elapsed < 1000, "Waited {$elapsed} ms.");
    }

    #[Test]
    public function wakesUpOnWritableSocket(): void
    {
        [$server, $client, $peer] = $this->connect();
        $pacer = new LoopPacer(idleTimeout: 5_000_000, grace: 0);

        try {
            $start = \hrtime(true);
            $ready = $pacer->pause(false, static fn(): array => [[$server], [$peer]]);
            $elapsed = (\hrtime(true) - $start) / 1e6;
        } finally {
            \socket_close($client);
            \socket_close($peer);
            \socket_close($server);
        }

        Assert::same($ready, [$peer]);
        Assert::true($elapsed < 1000, "Waited {$elapsed} ms.");
    }

    #[Test]
    public function sleepsWhenReadySocketsAreNotTaken(): void
    {
        [$server, $client, $peer] = $this->connect();
        $pacer = new LoopPacer(idleTimeout: 1_000, grace: 0);
        $asked = 0;
        $sockets = static function () use (&$asked, $peer): array {
            ++$asked;
            return [[$peer], []];
        };

        try {
            \socket_write($client, 'nobody reads it');
            \usleep(50_000);
            for ($i = 0; $i < 4; ++$i) {
                $pacer->pause(false, $sockets);
            }
            $selects = $asked;

            $pacer->pause(true, $sockets);
            $pacer->pause(false, $sockets);
        } finally {
            \socket_close($client);
            \socket_close($peer);
            \socket_close($server);
        }

        Assert::same($selects, 3);
        Assert::same($asked, 4);
    }

    #[Test]
    public function startsCountingUntakenSocketsAgainAfterSleepOrActivity(): void
    {
        [$server, $client, $peer] = $this->connect();
        $pacer = new LoopPacer(idleTimeout: 50_000, grace: 0);
        $asked = 0;
        $sockets = static function () use (&$asked, $peer): array {
            ++$asked;
            return [[$peer], []];
        };
        $pause = static function (int $times) use ($pacer, $sockets): void {
            for ($i = 0; $i < $times; ++$i) {
                $pacer->pause(false, $sockets);
            }
        };

        try {
            \socket_write($client, 'nobody reads it');
            \usleep(50_000);
            $pause(3);
            $start = \hrtime(true);
            $pause(1);
            $slept = (\hrtime(true) - $start) / 1e6;
            $pause(4);
            $afterSleep = $asked;
            $pacer->pause(true, $sockets);
            $pause(4);
        } finally {
            \socket_close($client);
            \socket_close($peer);
            \socket_close($server);
        }

        Assert::true($slept >= 40, "Slept {$slept} ms.");
        Assert::same($afterSleep, 6);
        Assert::same($asked, 9);
    }

    #[Test]
    public function startsCountingUntakenSocketsAgainAfterWaitWithoutThem(): void
    {
        [$server, $client, $peer] = $this->connect();
        $pacer = new LoopPacer(idleTimeout: 1_000, grace: 0);
        $asked = 0;
        $sockets = static function () use (&$asked, $peer): array {
            return ++$asked === 3 ? [[], []] : [[$peer], []];
        };
        $askedByPause = [];

        try {
            \socket_write($client, 'nobody reads it');
            \usleep(50_000);
            // Two waits end on the socket, one waits without it: three more waits before the loop sleeps
            for ($i = 0; $i < 7; ++$i) {
                $pacer->pause(false, $sockets);
                $askedByPause[] = $asked;
            }
        } finally {
            \socket_close($client);
            \socket_close($peer);
            \socket_close($server);
        }

        Assert::same($askedByPause, [1, 2, 3, 4, 5, 6, 6]);
    }

    #[Test]
    public function returnsEveryReadySocket(): void
    {
        [$server, $client, $peer] = $this->connect();
        [$server2, $client2, $peer2] = $this->connect();
        $pacer = new LoopPacer(idleTimeout: 1_000_000, grace: 0);

        try {
            \socket_write($client, 'ping');
            \socket_write($client2, 'ping');
            \usleep(50_000);
            $ready = $pacer->pause(false, static fn(): array => [[$peer, $peer2], []]);
        } finally {
            \array_map(\socket_close(...), [$client, $peer, $server, $client2, $peer2, $server2]);
        }

        Assert::same($ready, [$peer, $peer2]);
    }

    #[Test]
    public function waitsForWritingOnly(): void
    {
        [$server, $client, $peer] = $this->connect();
        $pacer = new LoopPacer(idleTimeout: 1_000_000);

        try {
            $ready = $pacer->wait([], [$peer]);
        } finally {
            \array_map(\socket_close(...), [$client, $peer, $server]);
        }

        Assert::same($ready, [$peer]);
    }

    #[Test]
    public function sleepsWithoutSockets(): void
    {
        $pacer = new LoopPacer(idleTimeout: 20_000, grace: 0);

        $start = \hrtime(true);
        $ready = $pacer->wait([]);
        $elapsed = (\hrtime(true) - $start) / 1e6;

        Assert::same($ready, []);
        Assert::true($elapsed >= 15, "Waited {$elapsed} ms.");
    }

    /**
     * @return array{\Socket, \Socket, \Socket} Listening socket, client socket, accepted socket.
     */
    private function connect(): array
    {
        $server = \socket_create(\AF_INET, \SOCK_STREAM, \SOL_TCP);
        \socket_bind($server, '127.0.0.1', 0);
        \socket_listen($server);
        \socket_getsockname($server, $address, $port);

        $client = \socket_create(\AF_INET, \SOCK_STREAM, \SOL_TCP);
        \socket_connect($client, '127.0.0.1', $port);
        $peer = \socket_accept($server);
        \assert($peer instanceof \Socket);

        return [$server, $client, $peer];
    }
}
