<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\XDebug;

use Buggregator\Trap\Module\Frontend\Publisher;
use Buggregator\Trap\Module\XDebug\Config\Debugger as Config;
use Buggregator\Trap\Module\XDebug\Dbgp\Response;
use Buggregator\Trap\Module\XDebug\Link\Link;
use Buggregator\Trap\Module\XDebug\Link\SocketLink;
use Buggregator\Trap\Server\Logger;
use Internal\Container\Container;

/**
 * The debug sessions of this process, their settings, the saved breakpoints and the files seen in them.
 * Events go to the UI on the `xdebug` WebSocket channel.
 *
 * A container singleton; off until the `--xdebug` option creates the {@see Sender\DebuggerSender}.
 *
 * @internal
 */
final class Debugger
{
    public const CHANNEL = 'xdebug';

    /** Seconds a closed session stays in the list */
    private const TOMBSTONE_TTL = 600.0;

    private const MAX_TOMBSTONES = 50;
    private const MAX_FILES = 2000;

    /** Sessions open at once at most: a script beyond them runs on without a debugger */
    public const MAX_LIVE_SESSIONS = 20;

    /** Seconds an IDE that didn't answer is not asked again */
    private const IDE_RETRY = 10.0;

    public readonly Breakpoints $breakpoints;
    private float $ideDownUntil = 0.0;
    private bool $enabled = false;
    private string $mode;
    private bool $pauseOnFirstLine;
    private int $nextId = 0;

    /** @var array<string, Session> Live sessions and tombstones */
    private array $sessions = [];

    /** @var array<string, true> File URIs seen in pauses and stacks, the oldest first */
    private array $files = [];

    public function __construct(
        public readonly Config $config,
        private readonly Container $container,
        private readonly Logger $logger,
    ) {
        $this->breakpoints = new Breakpoints();
        $this->mode = $config->mode();
        $this->pauseOnFirstLine = $config->pauseOnFirstLine;
    }

    public function enable(): void
    {
        $this->enabled = true;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function allowEval(): bool
    {
        return $this->config->allowEval;
    }

    /**
     * @return array{mode: string, pause_on_first_line: bool, idle_timeout_seconds: int}
     */
    public function settings(): array
    {
        return [
            'mode' => $this->mode,
            'pause_on_first_line' => $this->pauseOnFirstLine,
            'idle_timeout_seconds' => \max(0, $this->config->idleTimeout),
        ];
    }

    /**
     * Applies to new sessions only.
     *
     * @throws DebuggerError
     */
    public function changeSettings(?string $mode, ?bool $pauseOnFirstLine): void
    {
        $mode === null || \in_array($mode, Config::MODES, true)
            or throw DebuggerError::badRequest("mode must be proxy, auto or standalone, got `$mode`.");
        $this->mode = $mode ?? $this->mode;
        $this->pauseOnFirstLine = $pauseOnFirstLine ?? $this->pauseOnFirstLine;
    }

    /**
     * The address Xdebug connects to, as `host:port`.
     */
    public function address(string $tcpHost): string
    {
        return ($this->config->host ?? $tcpHost) . ':' . $this->config->port;
    }

    /**
     * Starts a session for an engine that sent its init packet: relays it to the IDE, or drives it here.
     * Connecting to the IDE suspends the current fiber.
     *
     * @param \Closure(non-empty-string): (Link|null)|null $connectIde Connects to the IDE; by default over TCP.
     *
     * @return Session|null Null when the session ended at once: proxy mode with no IDE.
     *
     * @throws Dbgp\ProtocolError When the init packet is not XML.
     */
    public function start(Link $engine, string $initBody, ?\Closure $connectIde = null): ?Session
    {
        $session = new Session(
            (string) ++$this->nextId,
            $engine,
            Response::parse($initBody),
            $this,
            idleTimeout: (float) \max(0, $this->config->idleTimeout),
        );

        $live = \count(\array_filter($this->sessions, static fn(Session $s): bool => !$s->isClosed()));
        if ($live >= self::MAX_LIVE_SESSIONS) {
            $this->logger->warning(
                'Xdebug: %d sessions are open already; the new script runs on without a debugger.',
                $live,
            );
            $session->detach();
            return null;
        }

        $ide = null;
        // An IDE that didn't answer a moment ago is not asked again: each try delays the script
        if ($this->mode !== Config::MODE_STANDALONE && \microtime(true) >= $this->ideDownUntil) {
            $ide = ($connectIde ?? SocketLink::connect(...))($this->config->ideAddr);
            if ($ide !== null && !$ide->send(Dbgp\Protocol::encodePacket($initBody))) {
                $ide->close();
                $ide = null;
            }
            $this->ideDownUntil = $ide === null ? \microtime(true) + self::IDE_RETRY : 0.0;
        }
        if ($this->mode !== Config::MODE_STANDALONE) {
            if ($ide === null && $this->mode === Config::MODE_PROXY) {
                $this->logger->info(
                    'Xdebug: the IDE at %s is not reachable; the script runs on without a debugger.',
                    $this->config->ideAddr,
                );
                $session->detach();
                $this->register($session);
                $session->close(Session::CLOSED_IDE_UNREACHABLE);
                return null;
            }
        }

        if ($ide !== null) {
            $session->relayTo($ide);
            $this->register($session);
            $this->logger->info('Xdebug: session %s started, relayed to the IDE at %s.', $session->id, $this->config->ideAddr);
        } else {
            $this->register($session);
            $session->driveHere($this->pauseOnFirstLine);
            $this->logger->info('Xdebug: session %s started, driven here: open the Xdebug page of the UI.', $session->id);
        }

        return $session;
    }

    /**
     * @return list<Session> The oldest first.
     */
    public function sessions(): array
    {
        $now = new \DateTimeImmutable();
        $tombstones = [];
        foreach ($this->sessions as $id => $session) {
            $closedAt = $session->closedAt();
            if ($closedAt === null) {
                continue;
            }
            if ((float) $now->format('U.u') - (float) $closedAt->format('U.u') > self::TOMBSTONE_TTL) {
                unset($this->sessions[$id]);
                continue;
            }
            $tombstones[] = $id;
        }
        foreach (\array_slice($tombstones, 0, \max(0, \count($tombstones) - self::MAX_TOMBSTONES)) as $id) {
            unset($this->sessions[$id]);
        }

        return \array_values($this->sessions);
    }

    /**
     * @throws DebuggerError
     */
    public function session(string $id): Session
    {
        $this->sessions();

        return $this->sessions[$id] ?? throw DebuggerError::notFound("No Xdebug session `$id`.");
    }

    /**
     * The session the agent means: the given one, or else the only paused one.
     *
     * @throws DebuggerError
     */
    public function pick(?string $id): Session
    {
        if ($id !== null && $id !== '') {
            return $this->session($id);
        }

        $live = \array_filter($this->sessions(), static fn(Session $s): bool => !$s->isClosed());
        $paused = \array_values(\array_filter($live, static fn(Session $s): bool => $s->isPaused()));

        return match (true) {
            \count($paused) === 1 => $paused[0],
            $live === [] => throw DebuggerError::notFound(
                'No Xdebug sessions: start a PHP request or script with Xdebug in debug mode.',
            ),
            $paused === [] => throw DebuggerError::notPaused(),
            default => throw DebuggerError::badRequest('Several sessions are paused: pass session_id.'),
        };
    }

    /**
     * Removes a closed session from the list.
     *
     * @throws DebuggerError
     */
    public function dismiss(string $id): void
    {
        $this->session($id)->isClosed() or throw DebuggerError::sessionLive();
        unset($this->sessions[$id]);
    }

    public function seeFile(string $uri): void
    {
        if ($uri === '' || !\str_starts_with($uri, 'file://')) {
            return;
        }
        unset($this->files[$uri]);
        $this->files[$uri] = true;
        \count($this->files) > self::MAX_FILES and \array_shift($this->files);
    }

    /**
     * Whether the file is one a session ran (an entry file, a pause, a stack) or a breakpoint names:
     * the only files the UI may read the source of.
     */
    public function knowsFile(string $uri): bool
    {
        if (isset($this->files[$uri])) {
            return true;
        }
        foreach ($this->breakpoints->all() as $bp) {
            if (($bp['file'] ?? null) === $uri) {
                return true;
            }
        }

        return false;
    }

    /**
     * Files seen in earlier pauses and stacks whose path contains the query, the latest seen first.
     *
     * @return list<string>
     */
    public function files(string $query, int $limit): array
    {
        $found = [];
        foreach (\array_reverse(\array_keys($this->files)) as $uri) {
            if ($query === '' || \stripos($uri, $query) !== false) {
                $found[] = (string) $uri;
                if (\count($found) >= $limit) {
                    break;
                }
            }
        }

        return $found;
    }

    /**
     * Tells the UI, and sends the breakpoints to the sessions driven here; a running one gets them at its next pause.
     */
    public function breakpointsChanged(): void
    {
        $this->emit('breakpoints.changed', $this->breakpoints->all());
        foreach ($this->sessions() as $session) {
            $session->isClosed() || $session->driver !== Session::DRIVER_HERE or $session->syncLater();
        }
    }

    /**
     * Publishes an event on the `xdebug` channel of the UI.
     *
     * @param non-empty-string $event
     */
    public function emit(string $event, mixed $data): void
    {
        // Resolved here: the publisher's connection pool serves the UI controllers, this one's among them
        $this->container->get(Publisher::class)->publish(self::CHANNEL, $event, $data);
    }

    private function register(Session $session): void
    {
        $this->sessions[$session->id] = $session;
        $this->seeFile($session->fileUri);
        $this->emit('session.created', $session->info());
    }
}
