<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\XDebug;

use Buggregator\Trap\Module\XDebug\Dbgp\PacketReader;
use Buggregator\Trap\Module\XDebug\Dbgp\ProtocolError;
use Buggregator\Trap\Module\XDebug\Link\Link;
use Buggregator\Trap\Server\Logger;
use Buggregator\Trap\Support\Timer;

/**
 * Runs one Xdebug connection in the current fiber: waits for the init packet, starts the session,
 * then moves the packets between the engine and the IDE until either side goes.
 *
 * @internal
 */
final class Connection
{
    /** Seconds the engine has to send its init packet */
    private const INIT_TIMEOUT = 10.0;

    public function __construct(
        private readonly Debugger $debugger,
        private readonly Logger $logger,
    ) {}

    /**
     * @param \Closure(non-empty-string): (Link|null)|null $connectIde
     */
    public function run(Link $engine, ?\Closure $connectIde = null): void
    {
        $packets = new PacketReader();
        try {
            $init = $this->init($engine, $packets);
            if ($init === null) {
                return;
            }

            if (!$this->debugger->isEnabled()) {
                $this->logger->warning(
                    'Xdebug connected to Trap, but the debugger is off: run Trap with --xdebug. The script runs on.',
                );
                $engine->send("detach -i 1\0");
                return;
            }

            $session = $this->debugger->start($engine, $init, $connectIde);
        } catch (ProtocolError $e) {
            $this->logger->warning('Xdebug: %s', $e->getMessage());
            return;
        } finally {
            isset($session) or $engine->close();
        }

        if ($session === null) {
            return;
        }

        $this->loop($session, $engine, $packets);
    }

    /**
     * @return non-empty-string|null
     */
    private function init(Link $engine, PacketReader $packets): ?string
    {
        $timer = new Timer(self::INIT_TIMEOUT);
        while (true) {
            $data = $engine->receive();
            if ($data === null || $timer->isReady()) {
                return null;
            }
            foreach ($packets->push($data) as $packet) {
                if ($packet !== '') {
                    return $packet;
                }
            }
            \Fiber::suspend();
        }
    }

    private function loop(Session $session, Link $engine, PacketReader $packets): void
    {
        $ide = $session->ide();
        $commands = new PacketReader(lengthPrefixed: false);
        $reason = Session::CLOSED_ENGINE;
        try {
            while (true) {
                $data = $engine->receive();
                if ($data === null) {
                    break;
                }
                foreach ($packets->push($data) as $packet) {
                    $session->onEnginePacket($packet);
                }

                if ($ide !== null) {
                    $data = $ide->receive();
                    if ($data === null) {
                        $reason = Session::CLOSED_IDE;
                        break;
                    }
                    foreach ($commands->push($data) as $command) {
                        $session->onIdeCommand($command);
                    }
                }

                $session->process();
                \Fiber::suspend();
            }
        } catch (ProtocolError $e) {
            $this->logger->warning('Xdebug session %s: %s', $session->id, $e->getMessage());
        } finally {
            $session->close($reason);
            if ($reason === Session::CLOSED_IDE) {
                $this->logger->info('Xdebug: the IDE left session %s; the script runs on.', $session->id);
                $session->detach();
            }
            $engine->close();
            $this->logger->info('Xdebug: session %s ended.', $session->id);
        }
    }
}
