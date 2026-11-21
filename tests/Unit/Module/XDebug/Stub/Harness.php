<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\XDebug\Stub;

use Buggregator\Trap\Module\Frontend\ConnectionPool;
use Buggregator\Trap\Module\XDebug\Config\Debugger as Config;
use Buggregator\Trap\Module\XDebug\Connection;
use Buggregator\Trap\Module\XDebug\Debugger;
use Buggregator\Trap\Module\XDebug\Session;
use Buggregator\Trap\Server\Logger;
use Buggregator\Trap\Server\Traffic\StreamClient;
use Internal\Container\ObjectContainer;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * A debugger with one Xdebug connection run in a fiber, and the events the UI got.
 */
final class Harness
{
    public readonly Debugger $debugger;
    public readonly BufferedOutput $output;

    /** @var list<array{event: string, data: mixed}> */
    public array $events = [];

    private ?\Fiber $connection = null;

    public function __construct(
        public readonly FakeEngine $engine = new FakeEngine(),
        public readonly Config $config = new Config(),
    ) {
        $container = new ObjectContainer();
        $this->output = new BufferedOutput();
        $logger = new Logger($this->output);
        $container->set($logger);
        $ui = \Mockery::mock(StreamClient::class);
        $ui->allows('sendData')->andReturnUsing(function (string $frame): bool {
            $data = \json_decode(self::payload($frame), true);
            isset($data['push']['pub']) and $this->events[] = $data['push']['pub']['data'];
            return true;
        });
        $container->get(ConnectionPool::class)->addStream($ui);
        $this->debugger = new Debugger($config, $container, $logger);
        $this->debugger->enable();
    }

    /**
     * Starts the connection of the engine; the IDE, when given, accepts the session.
     */
    public function connect(?FakeIde $ide = null): void
    {
        $this->engine->emit(FakeEngine::INIT);
        $connection = new Connection($this->debugger, new Logger($this->output));
        $this->connection = new \Fiber(fn() => $connection->run($this->engine, static fn(): ?FakeIde => $ide));
        $this->connection->start();
        $this->pump();
    }

    public function session(): Session
    {
        return $this->debugger->session('1');
    }

    /**
     * Lets the connection run a few rounds.
     */
    public function pump(int $rounds = 10): void
    {
        for ($i = 0; $i < $rounds && $this->connection?->isTerminated() === false; ++$i) {
            $this->connection->resume();
        }
    }

    public function isRunning(): bool
    {
        return $this->connection?->isTerminated() === false;
    }

    /**
     * Runs a call in a fiber of its own, as an HTTP request does, while the connection runs.
     */
    public function call(\Closure $call): mixed
    {
        $fiber = new \Fiber($call);
        $fiber->start();
        for ($i = 0; $i < 1000 && !$fiber->isTerminated(); ++$i) {
            $this->connection?->isTerminated() === false and $this->connection->resume();
            $fiber->resume();
        }
        $fiber->isTerminated() or throw new \LogicException('The call did not end.');

        return $fiber->getReturn();
    }

    /**
     * @return list<string>
     */
    public function eventNames(): array
    {
        return \array_column($this->events, 'event');
    }

    /**
     * The payload of a server WebSocket text frame: unmasked, with a 7, 16 or 64-bit length.
     */
    private static function payload(string $frame): string
    {
        $length = \ord($frame[1]) & 127;

        return \substr($frame, match ($length) {
            126 => 4,
            127 => 10,
            default => 2,
        });
    }
}
