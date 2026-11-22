<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\XDebug;

use Buggregator\Trap\Module\XDebug\Dbgp\EngineError;
use Buggregator\Trap\Module\XDebug\Dbgp\Protocol;
use Buggregator\Trap\Module\XDebug\Dbgp\ProtocolError;
use Buggregator\Trap\Module\XDebug\Dbgp\Response;
use Buggregator\Trap\Module\XDebug\Link\Link;
use Buggregator\Trap\Support\Timer;

/**
 * One Xdebug connection: relayed to the IDE, or driven here by the UI and the agents.
 *
 * Everything runs in fibers of one thread: the connection fiber reads the engine and the IDE and calls
 * {@see onEnginePacket()}, {@see onIdeCommand()} and {@see process()}; the HTTP and MCP fibers send commands
 * and suspend until the connection fiber has put the reply in place. Commands of the session itself, as the
 * breakpoint sync, run in task fibers of the session.
 *
 * Transaction IDs on the engine side are the session's own: the IDE's ones are mapped back on the replies.
 *
 * @psalm-import-type Breakpoint from Breakpoints
 * @psalm-type Pending = array{kind: 'ide'|'reply'|'own', cont: bool, ide_tid?: string}
 *
 * @internal
 */
final class Session
{
    public const DRIVER_IDE = 'ide';
    public const DRIVER_HERE = 'here';
    public const BY_UI = 'ui';
    public const BY_AGENT = 'agent';
    public const CLOSED_IDE_UNREACHABLE = 'ide_unreachable';
    public const CLOSED_IDE = 'ide_closed';
    public const CLOSED_ENGINE = 'engine_closed';
    public const CLOSED_STOPPED = 'stopped';
    public const CLOSED_DETACHED = 'detached';
    public const CLOSED_IDLE = 'idle_detached';

    /** Commands that resume the script: after one, the engine runs until its reply */
    public const CONTINUATIONS = ['run', 'step_into', 'step_over', 'step_out', 'stop', 'detach'];

    /** What the UI and the agents may send; eval is gated apart */
    public const READS = [
        'status', 'stack_depth', 'stack_get', 'context_names', 'context_get', 'property_get', 'property_value',
        'source', 'breakpoint_list', 'typemap_get', 'eval',
    ];

    /** Features a session driven here asks for */
    private const FEATURES = [
        'max_depth -v 1',
        'max_children -v 100',
        'max_data -v 65536',
        'resolved_breakpoints -v 1',
        'notify_ok -v 1',
    ];

    /**
     * Puts an error handler on top for an eval: it keeps the first warning or notice of the eval code and
     * silences its deprecations, and passes the other errors to the script's handler. In a closure of its own:
     * the eval runs in the scope of the paused frame. PHP 7 syntax: the debugged script may run an old PHP.
     */
    private const EVAL_CATCH = <<<'PHP'
        (static function () {
            $previous = \set_error_handler(static function () { return false; });
            \restore_error_handler();
            \set_error_handler(new class ($previous) {
                public $trapEval = true;
                public $error;
                private $previous;
                public function __construct($previous) { $this->previous = $previous; }
                public function __invoke($type, $message, $file = '', $line = 0) {
                    if ($file !== 'xdebug://debug-eval') {
                        return $this->previous !== null && \call_user_func($this->previous, $type, $message, $file, $line) !== false;
                    }
                    if ((\error_reporting() & $type) !== 0 && ($type & (\E_DEPRECATED | \E_USER_DEPRECATED)) === 0) {
                        $this->error = $this->error ?: $type . ' ' . $message;
                    }
                    return true;
                }
            });
        })()
        PHP;

    /** Takes the handler of {@see EVAL_CATCH} off and returns what it kept: `<type> <message>`, or null */
    private const EVAL_RELEASE = <<<'PHP'
        (static function () {
            $handler = \set_error_handler(static function () { return false; });
            \restore_error_handler();
            if (!\is_object($handler) || !isset($handler->trapEval)) {
                return null;
            }
            \restore_error_handler();
            return $handler->error;
        })()
        PHP;

    private const AGENT_LOG_SIZE = 200;
    private const MAX_RAN_FILES = 2000;

    /** Bytes of a file Trap reads itself at most, and of a line of it */
    private const MAX_LOCAL_SOURCE = 4 << 20;

    private const MAX_LOCAL_LINE = 64 << 10;
    private const MAX_CACHED_READS = 256;

    public readonly string $ideKey;
    public readonly string $fileUri;
    public readonly string $language;
    public readonly string $engineName;
    public readonly \DateTimeImmutable $startedAt;
    public string $driver = self::DRIVER_HERE;
    private ?Link $ide = null;
    private int $nextTid = 0;

    /** @var array<int, Pending> */
    private array $pending = [];

    /** @var array<int, string|false> Replies by transaction ID; false when the session closed first */
    private array $replies = [];

    private string $status = 'starting';

    /** Continuations sent and not answered yet: the engine runs */
    private int $conts = 0;

    private string $file = '';
    private int $line = 0;

    /** @var array{class: string, message: string, code?: string}|null The exception of the current pause */
    private ?array $exception = null;

    /** @var array<string, true> Files the engine reported running: the entry file, pauses, stacks */
    private array $ran = [];

    private bool $closed = false;
    private string $closeReason = '';
    private ?\DateTimeImmutable $closedAt = null;
    private int $pauseSeq = 0;
    private ?\DateTimeImmutable $pausedAt = null;

    /** @var array<string, string> Replies of the reads of the current pause */
    private array $cache = [];

    private int $cacheSeq = 0;
    private string $kind = '';
    private string $target = '';
    private bool $targetDone = false;
    private int $agentCalls = 0;

    /** @var list<array{seq: int, session_id: string, at: string, tool: string, args: string, ok: bool, result_summary: string, duration_ms: int}> */
    private array $agentLog = [];

    private ?string $lastContBy = null;
    private string $lastContAgent = '';
    private bool $detaching = false;
    private bool $idleRelease = false;
    private ?float $idleDeadline = null;
    private ?float $idleSent = null;

    /** One read of the UI or an agent at a time */
    private bool $busy = false;

    /** @var array<int, array{engine_id: string, sig: string, state: string, resolved_line: int, hit_count?: int}> By saved ID */
    private array $bps = [];

    /** Breakpoints changed while the script ran: synced at the next pause */
    private bool $bpDirty = false;

    /** @var array<string, int> Lines of `breakpoint_resolved` notifications that came before the reply of the set */
    private array $bpEarly = [];

    /** @var list<\Fiber|null> */
    private array $tasks = [];

    /**
     * @param float $timeout Seconds a command waits for its reply.
     * @param float $idleTimeout Seconds a session paused here waits for a command; 0 for ever.
     */
    public function __construct(
        public readonly string $id,
        private readonly Link $engine,
        Response $init,
        private readonly Debugger $debugger,
        private readonly float $timeout = 10.0,
        private readonly float $idleTimeout = 600.0,
    ) {
        $this->ideKey = $init->attr('idekey');
        $this->fileUri = $init->attr('fileuri');
        $this->language = $init->attr('language');
        $engine = $init->xml->engine;
        $this->engineName = \trim((string) $engine . ' ' . (string) ($engine['version'] ?? ''));
        $this->startedAt = new \DateTimeImmutable();
        $this->ran($this->fileUri);
    }

    /**
     * Relays the session to the IDE, which has got the init packet.
     */
    public function relayTo(Link $ide): void
    {
        $this->ide = $ide;
        $this->driver = self::DRIVER_IDE;
    }

    /**
     * Drives the session here: asks for the features, sends the saved breakpoints, and runs to the first stop.
     */
    public function driveHere(bool $pauseOnFirstLine): void
    {
        $this->driver = self::DRIVER_HERE;
        $this->task(function () use ($pauseOnFirstLine): void {
            foreach (self::FEATURES as $feature) {
                $this->sendOwn("feature_set -n $feature", false);
            }
            $this->syncBreakpoints();
            $this->sendOwn($pauseOnFirstLine ? 'step_into' : 'run', true);
        });
    }

    /**
     * Handles a packet of the engine.
     *
     * @throws ProtocolError
     */
    public function onEnginePacket(string $body): void
    {
        $root = Protocol::root($body);
        $root['name'] === 'notify' and $this->onNotify($body);
        if ($root['name'] !== 'response' || $root['tid'] === '') {
            $this->toIde($body);
            return;
        }

        $tid = (int) $root['tid'];
        $pending = $this->pending[$tid] ?? null;
        unset($this->pending[$tid]);
        $pending !== null && $pending['cont'] and --$this->conts;

        $previous = $this->status;
        // While a continuation is in flight the engine runs: a late reply to an earlier command must not
        // report it paused again
        if ($root['status'] !== '' && $this->conts === 0) {
            [$file, $line] = [$this->file, $this->line];
            $this->status = $root['status'];
            if ($root['status'] === 'break') {
                // Xdebug puts `status="break"` on plain replies too: only a reply with a location moves the line
                $reply = Response::parse($body);
                $location = $reply->breakLocation();
                if ($location !== null) {
                    [$this->file, $this->line] = $location;
                    $this->exception = $reply->breakException();
                    $this->ran($this->file);
                }
                $previous === 'break' or $this->paused();
            }
            if ($previous !== $this->status || $file !== $this->file || $line !== $this->line) {
                $this->debugger->emit('session.status', $this->statusEvent());
                $this->file === '' or $this->debugger->seeFile($this->file);
            }
        }

        if ($this->driver === self::DRIVER_HERE && $this->status === 'stopping' && $previous !== 'stopping'
            && $this->conts === 0
        ) {
            // The script has ended: let the engine finish
            $this->sendOwn('stop', true);
        }

        match ($pending['kind'] ?? null) {
            'reply' => $this->replies[$tid] = $body,
            'ide' => $this->toIde(Protocol::withTid($body, $pending['ide_tid'] ?? '')),
            default => null,
        };
    }

    /**
     * Passes a command of the IDE to the engine, with a transaction ID of the session.
     */
    public function onIdeCommand(string $line): void
    {
        $command = Protocol::commandTid($line);
        if ($command === null || $this->closed) {
            // The engine answers it with an error the IDE can match
            $this->engine->send($line . "\0");
            return;
        }

        [$name, $start, $end] = $command;
        $tid = ++$this->nextTid;
        $cont = \in_array($name, self::CONTINUATIONS, true);
        $this->pending[$tid] = ['kind' => 'ide', 'cont' => $cont, 'ide_tid' => \substr($line, $start, $end - $start)];
        $cont and $this->running();
        $this->engine->send(\substr($line, 0, $start) . $tid . \substr($line, $end) . "\0");
    }

    /**
     * Runs the session's tasks and releases a session paused here for too long. Called by the connection fiber.
     */
    public function process(): void
    {
        foreach ($this->tasks as $key => $task) {
            if ($task === null) {
                continue;
            }
            try {
                $task->isStarted() ? $task->resume() : $task->start();
                $task->isTerminated() and $this->tasks[$key] = null;
            } catch (\Throwable) {
                $this->tasks[$key] = null;
            }
        }
        $this->tasks = \array_values(\array_filter($this->tasks));

        if ($this->idleDeadline !== null && \microtime(true) >= $this->idleDeadline && $this->status === 'break'
            && $this->conts === 0 && !$this->closed
        ) {
            $this->idleRelease = $this->detaching = true;
            $this->sendOwn('detach', true);
        }
    }

    /**
     * Turns the session into a tombstone: pending waits fail, the UI gets `session.closed`.
     * The connections are closed by their fiber.
     */
    public function close(string $reason): void
    {
        if ($this->closed) {
            return;
        }

        $reason = match (true) {
            $reason !== self::CLOSED_ENGINE => $reason,
            $this->idleRelease => self::CLOSED_IDLE,
            $this->detaching => self::CLOSED_DETACHED,
            $this->status === 'stopping' || $this->status === 'stopped' => self::CLOSED_STOPPED,
            default => $reason,
        };
        $this->closed = true;
        $this->closeReason = $reason;
        $this->closedAt = new \DateTimeImmutable();
        $this->idleDeadline = null;
        $this->cache = [];
        foreach ($this->pending as $tid => $pending) {
            $pending['kind'] === 'reply' and $this->replies[$tid] = false;
        }
        $this->pending = [];
        // Nobody resumes them any more; the tombstone would keep them for its lifetime
        $this->tasks = [];
        $this->ide?->close();
        $this->debugger->emit('session.closed', ['id' => $this->id, 'reason' => $reason, 'state' => $this->state()]);
    }

    /**
     * Lets the script run on without a debugger.
     */
    public function detach(): void
    {
        $this->status = 'running';
        $this->engine->send(Protocol::command('detach', ++$this->nextTid) . "\0");
    }

    public function ide(): ?Link
    {
        return $this->ide;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function isPaused(): bool
    {
        return !$this->closed && $this->status === 'break';
    }

    public function closedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function pauseSeq(): int
    {
        return $this->pauseSeq;
    }

    /**
     * The state shown to the clients: `starting`, `break`, `running`, `stopping`, `stopped` or `detached`.
     */
    public function state(): string
    {
        if (!$this->closed) {
            return $this->status;
        }

        return \in_array($this->closeReason, [self::CLOSED_IDE_UNREACHABLE, self::CLOSED_IDE, self::CLOSED_DETACHED, self::CLOSED_IDLE], true)
            ? 'detached'
            : 'stopped';
    }

    /**
     * @return array<array-key, mixed>
     */
    public function info(): array
    {
        $optional = static fn(array $fields): array => \array_filter(
            $fields,
            static fn(mixed $v): bool => $v !== '' && $v !== null && $v !== 0,
        );

        return [
            'id' => $this->id,
            'idekey' => $this->ideKey,
            'state' => $this->state(),
            'entry_file' => $this->fileUri,
            'ide_connected' => $this->ide !== null && !$this->closed,
            'started_at' => self::time($this->startedAt),
            'agent_calls' => $this->agentCalls,
        ] + $optional([
            'kind' => $this->kind,
            'target' => $this->target,
            'language' => $this->language,
            'engine' => $this->engineName,
            'last_file' => $this->file,
            'last_line' => $this->line,
            'paused_at' => self::time($this->pausedAt),
            'closed_reason' => $this->closeReason,
            'closed_at' => self::time($this->closedAt),
        ]) + $this->driving();
    }

    /**
     * Sends a read of the UI or an agent while the session is paused, and waits for the reply.
     * Reads of one pause are cached: the page asks the same ones again.
     *
     * @throws DebuggerError
     */
    public function read(string $name, string $args = '', bool $cache = true): Response
    {
        $this->assertPaused();
        if ($this->cacheSeq !== $this->pauseSeq) {
            $this->cache = [];
            $this->cacheSeq = $this->pauseSeq;
        }
        $key = "$name $args";
        if ($cache && isset($this->cache[$key])) {
            $this->touch();
            return self::ok($this->cache[$key]);
        }

        $seq = $this->pauseSeq;
        $body = $this->command($name, $args);
        if ($name === 'stack_get') {
            foreach (self::ok($body)->stack() as $frame) {
                $this->debugger->seeFile($frame['file']);
                $this->ran($frame['file']);
            }
        }
        $cache && $seq === $this->pauseSeq && \count($this->cache) < self::MAX_CACHED_READS
            and $this->cache[$key] = $body;

        return self::ok($body);
    }

    /**
     * Lines `$from` to `$to` of a file of the script, with the line breaks.
     *
     * @throws DebuggerError
     */
    public function source(string $file, int $from, int $to): string
    {
        try {
            return $this->read('source', \sprintf('-f %s -b %d -e %d', Protocol::quote($file), $from, $to))->text();
        } catch (DebuggerError $e) {
            $engine = $e->getPrevious();
            if ($this->exception === null || !$engine instanceof EngineError || $engine->engineCode !== '100') {
                throw $e;
            }
            // While PHP has an exception in flight its streams open nothing, so Xdebug can't read the file:
            // Trap reads it when it runs on the same host. Only a file the script ran: the UI names any file
            // with a breakpoint.
            return (isset($this->ran[$file]) ? self::localSource($file, $from, $to) : null)
                ?? throw DebuggerError::sourceAtException();
        }
    }

    /**
     * Evaluates a PHP expression in the paused script. Never cached: it may change the state.
     *
     * PHP would print a warning of the expression into the output of the script, or pass it to the script's
     * own error handler. An error handler of Trap takes the errors of the eval code around it instead, and a
     * warning or a notice fails the call; the other errors go on to the script's handler.
     *
     * @throws DebuggerError
     */
    public function evaluate(string $expression): Response
    {
        $this->assertPaused();

        return $this->exclusive(function () use ($expression): Response {
            self::ok($this->exchange('eval', '', self::EVAL_CATCH));
            $value = $this->exchange('eval', '', $expression);
            $caught = (string) (self::ok($this->exchange('eval', '', self::EVAL_RELEASE))->properties()[0]['value'] ?? '');
            $value = self::ok($value);
            if ($caught !== '') {
                [$type, $message] = \explode(' ', $caught, 2) + [1 => ''];
                $level = \in_array((int) $type, [\E_NOTICE, \E_USER_NOTICE], true) ? 'Notice' : 'Warning';
                throw DebuggerError::evalError("PHP $level in the expression: $message");
            }

            return $value;
        });
    }

    /**
     * The exception the session is paused at, from an exception breakpoint.
     *
     * @return array{class: string, message: string, code?: string}|null
     */
    public function exception(): ?array
    {
        return $this->isPaused() ? $this->exception : null;
    }

    /**
     * Resumes a session driven here. The reply comes with the next pause, as `session.status`.
     *
     * @param 'run'|'step_into'|'step_over'|'step_out'|'stop'|'detach' $command
     *
     * @throws DebuggerError
     */
    public function continue(string $command, string $by, string $agent = ''): void
    {
        $this->closed and throw DebuggerError::notPaused($this->state());
        $this->driver === self::DRIVER_HERE or throw DebuggerError::ideAttached($this->state());
        // A read in flight, as the three exchanges of an eval, ends in the pause it began in
        $timer = new Timer($this->timeout);
        while ($this->busy) {
            $timer->isReady() and throw DebuggerError::timeout();
            self::suspend();
        }
        $this->isPaused() && $this->conts === 0 or throw DebuggerError::notPaused($this->state());

        $this->lastContBy = $by;
        $this->lastContAgent = $by === self::BY_AGENT ? $agent : '';
        $this->detaching = $command === 'detach';
        $this->sendOwn($command, true);
    }

    /**
     * Waits until the session pauses after the given pause, or closes.
     *
     * @param \Closure(): bool|null $cancelled Stops the wait when it says so, as when the agent has gone.
     *
     * @return bool Whether it paused.
     */
    public function waitPause(int $afterSeq, float $seconds, ?\Closure $cancelled = null): bool
    {
        $timer = new Timer($seconds);
        while (!$this->closed && ($this->pauseSeq <= $afterSeq || $this->status !== 'break')) {
            if ($timer->isReady() || \Fiber::getCurrent() === null || ($cancelled !== null && $cancelled())) {
                return false;
            }
            \Fiber::suspend();
        }

        return !$this->closed;
    }

    /**
     * The saved breakpoints as this session has them.
     *
     * @param list<Breakpoint> $saved
     *
     * @return list<array<string, mixed>>
     */
    public function breakpointStates(array $saved): array
    {
        $this->readHits();
        $out = [];
        foreach ($saved as $bp) {
            $state = $this->bps[$bp['id']] ?? null;
            $out[] = $bp + \array_filter([
                'state' => $state['state'] ?? 'pending',
                'resolved_line' => $state['resolved_line'] ?? 0,
            ]) + ['hit_count' => $state['hit_count'] ?? 0];
        }

        return $out;
    }

    /**
     * Syncs the breakpoints in a task of the session: the caller doesn't wait for the engine.
     */
    public function syncLater(): void
    {
        $this->task($this->syncBreakpoints(...));
    }

    /**
     * Sends the saved breakpoints to a session driven here; while it runs, marks them for the next pause.
     */
    public function syncBreakpoints(): void
    {
        if ($this->driver !== self::DRIVER_HERE || $this->closed) {
            return;
        }
        if (!$this->canSend()) {
            $this->bpDirty = true;
            return;
        }
        $this->bpDirty = false;

        $want = [];
        foreach ($this->debugger->breakpoints->all() as $bp) {
            $want[$bp['id']] = true;
            $current = $this->bps[$bp['id']] ?? null;
            $sig = Breakpoints::signature($bp);
            $send = $bp['enabled'] && (!isset($bp['condition']) || $this->debugger->allowEval());
            try {
                if ($current !== null && $current['engine_id'] !== '' && (!$send || $current['sig'] !== $sig)) {
                    $this->ownCall('breakpoint_remove -d ' . $current['engine_id']);
                    $current['engine_id'] = '';
                    $this->bps[$bp['id']] = $current;
                }
                if (!$send) {
                    $this->bps[$bp['id']] = ['engine_id' => '', 'sig' => $sig, 'state' => $bp['enabled'] ? 'skipped' : 'disabled', 'resolved_line' => 0];
                    continue;
                }
                if ($current !== null && $current['engine_id'] !== '' && $current['sig'] === $sig) {
                    continue;
                }
                $reply = $this->ownCall(self::breakpointSet($bp));
                $engineId = $reply->attr('id');
                $resolved = $reply->attr('resolved');
                $state = $resolved === 'resolved' || ($resolved === '' && $bp['type'] === 'exception') ? 'resolved' : 'unresolved';
                $line = $this->bpEarly[$engineId] ?? 0;
                unset($this->bpEarly[$engineId]);
                $line > 0 and $state = 'resolved';
                $this->bps[$bp['id']] = ['engine_id' => $engineId, 'sig' => $sig, 'state' => $state, 'resolved_line' => $line];
                $line > 0 and $this->debugger->emit('breakpoint.resolved', ['session_id' => $this->id, 'breakpoint_id' => $bp['id'], 'line' => $line]);
            } catch (DebuggerError $e) {
                // Xdebug refused it, as for a file it doesn't know: nothing is set. Otherwise it is sent again
                // at the next pause.
                $this->bps[$bp['id']] = $e->errorCode === 'xdebug_error'
                    ? ['engine_id' => '', 'sig' => $sig, 'state' => 'unresolved', 'resolved_line' => 0]
                    : ['engine_id' => $current['engine_id'] ?? '', 'sig' => '', 'state' => 'pending', 'resolved_line' => 0];
                $e->errorCode === 'xdebug_error' or $this->bpDirty = true;
            }
        }

        foreach ($this->bps as $id => $current) {
            if (isset($want[$id])) {
                continue;
            }
            try {
                $current['engine_id'] === '' or $this->ownCall('breakpoint_remove -d ' . $current['engine_id']);
                unset($this->bps[$id]);
            } catch (DebuggerError $e) {
                if ($e->errorCode !== 'xdebug_error') {
                    $this->bpDirty = true;
                    continue;
                }
                unset($this->bps[$id]);
            }
        }
    }

    /**
     * Records an agent call: the UI shows who stepped and what was read.
     */
    public function agentStarted(string $tool, string $args): void
    {
        $this->debugger->emit('agent.call.started', [
            'session_id' => $this->id,
            'tool' => $tool,
            'args' => $args,
            'at' => self::time(new \DateTimeImmutable()),
        ]);
    }

    public function agentDone(string $tool, string $args, float $startedAt, bool $ok, string $summary): void
    {
        $call = [
            'seq' => ++$this->agentCalls,
            'session_id' => $this->id,
            'at' => self::timestamp($startedAt),
            'tool' => $tool,
            'args' => $args,
            'ok' => $ok,
            'result_summary' => $summary,
            'duration_ms' => (int) \round((\microtime(true) - $startedAt) * 1000.0),
        ];
        $this->agentLog[] = $call;
        \count($this->agentLog) > self::AGENT_LOG_SIZE and \array_shift($this->agentLog);
        $this->debugger->emit('agent.call', $call);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function agentLog(): array
    {
        return $this->agentLog;
    }

    /**
     * Where the session is: the file and line of the last pause.
     *
     * @return array{string, int}
     */
    public function location(): array
    {
        return [$this->file, $this->line];
    }

    /**
     * @param Breakpoint $bp
     */
    private static function breakpointSet(array $bp): string
    {
        if ($bp['type'] === 'exception') {
            return 'breakpoint_set -t exception -x ' . Protocol::quote($bp['exception'] ?? '*');
        }
        $target = '-f ' . Protocol::quote($bp['file'] ?? '') . ' -n ' . ($bp['line'] ?? 0);

        return isset($bp['condition'])
            ? "breakpoint_set -t conditional $target -- " . \base64_encode($bp['condition'])
            : "breakpoint_set -t line $target";
    }

    /**
     * @throws DebuggerError
     */
    private static function ok(string $body): Response
    {
        try {
            return Response::parse($body)->assertOk();
        } catch (EngineError $e) {
            throw DebuggerError::engine($e);
        } catch (ProtocolError $e) {
            throw DebuggerError::badRequest($e->getMessage());
        }
    }

    /**
     * Lines of a `file:///` URI read from this host, a line cut at {@see MAX_LOCAL_LINE} bytes; null when it
     * has no such file, the file is larger than {@see MAX_LOCAL_SOURCE}, or the URI is not a plain local path.
     */
    private static function localSource(string $uri, int $from, int $to): ?string
    {
        // No host part: `file://name` would be relative to Trap's directory, `file:////host/share` a network share
        if (!\str_starts_with($uri, 'file:///')) {
            return null;
        }
        $path = \rawurldecode(\substr($uri, 7));
        \preg_match('#^/[a-z]:/#i', $path) === 1 and $path = \substr($path, 1);
        if (\preg_match('#^[/\\\\]{2}|\\\\\\\\|://|\0#', $path) === 1) {
            return null;
        }
        $real = \realpath($path);
        if ($real === false || !\is_file($real) || \filesize($real) > self::MAX_LOCAL_SOURCE) {
            return null;
        }
        $handle = @\fopen($real, 'rb');
        if ($handle === false) {
            return null;
        }

        $text = '';
        try {
            for ($n = 1; $n <= $to; ++$n) {
                $chunk = \fgets($handle, self::MAX_LOCAL_LINE);
                if ($chunk === false) {
                    break;
                }
                $whole = \str_ends_with($chunk, "\n") || \feof($handle);
                $n >= $from and $text .= $whole ? $chunk : "$chunk\n";
                while (!$whole && ($rest = \fgets($handle, self::MAX_LOCAL_LINE)) !== false) {
                    $whole = \str_ends_with($rest, "\n") || \feof($handle);
                }
            }
        } finally {
            \fclose($handle);
        }

        return $text;
    }

    private static function suspend(): void
    {
        \Fiber::getCurrent() === null and throw new \LogicException('A debugger command waits in a fiber.');
        \Fiber::suspend();
    }

    private static function time(?\DateTimeImmutable $time): ?string
    {
        return $time?->format(\DATE_RFC3339_EXTENDED);
    }

    private static function timestamp(float $time): string
    {
        return (string) self::time(\DateTimeImmutable::createFromFormat('U.u', \sprintf('%.6F', $time)) ?: null);
    }

    /**
     * @return array<non-empty-string, mixed>
     */
    private function statusEvent(): array
    {
        return [
            'id' => $this->id,
            'state' => $this->state(),
            'file' => $this->file,
            'line' => $this->line,
            'kind' => $this->kind,
            'target' => $this->target,
            'pause_seq' => $this->pauseSeq,
        ] + $this->driving();
    }

    /**
     * @return array{driver: string, last_continuation_by: string|null, last_continuation_agent?: string, idle_deadline: string|null}
     */
    private function driving(): array
    {
        return ['driver' => $this->driver, 'last_continuation_by' => $this->lastContBy]
            + ($this->lastContAgent === '' ? [] : ['last_continuation_agent' => $this->lastContAgent])
            + ['idle_deadline' => $this->idleDeadline === null || $this->closed ? null : self::timestamp($this->idleDeadline)];
    }

    /**
     * A new pause: the reads of the last one are stale.
     */
    private function paused(): void
    {
        ++$this->pauseSeq;
        $this->pausedAt = new \DateTimeImmutable();
        $this->targetDone or $this->task($this->resolveTarget(...));
        $this->bpDirty && $this->driver === self::DRIVER_HERE and $this->task($this->syncBreakpoints(...));
        $this->armIdle();
    }

    private function running(): void
    {
        ++$this->conts;
        $this->idleDeadline = $this->idleSent = null;
        $this->exception = null;
        if ($this->status !== 'running') {
            $this->status = 'running';
            $this->debugger->emit('session.status', $this->statusEvent());
        }
    }

    private function onNotify(string $body): void
    {
        $xml = Response::parse($body);
        if ($xml->attr('name') !== 'breakpoint_resolved') {
            return;
        }
        $bp = $xml->xml->breakpoint;
        $engineId = (string) $bp['id'];
        $line = (int) $bp['lineno'];
        foreach ($this->bps as $id => $state) {
            if ($state['engine_id'] === $engineId) {
                $this->bps[$id]['state'] = 'resolved';
                $this->bps[$id]['resolved_line'] = $line;
                $this->debugger->emit('breakpoint.resolved', ['session_id' => $this->id, 'breakpoint_id' => $id, 'line' => $line]);
                return;
            }
        }
        // Xdebug resolves a breakpoint before it answers the set
        $this->driver === self::DRIVER_HERE and $this->bpEarly[$engineId] = $line;
    }

    /**
     * Takes the hit counts and the resolved lines from Xdebug while paused; otherwise the last ones read stay.
     */
    private function readHits(): void
    {
        if ($this->driver !== self::DRIVER_HERE || !$this->isPaused() || $this->bps === [] || \Fiber::getCurrent() === null) {
            return;
        }
        try {
            $engine = [];
            foreach ($this->read('breakpoint_list')->breakpoints() as $bp) {
                $engine[$bp['id']] = $bp;
            }
        } catch (DebuggerError) {
            return;
        }
        foreach ($this->bps as $id => $state) {
            $bp = $engine[$state['engine_id']] ?? null;
            if ($state['engine_id'] === '' || $bp === null) {
                continue;
            }
            $this->bps[$id]['hit_count'] = $bp['hit_count'];
            $bp['resolved'] === 'resolved' && $bp['line'] > 0
                and [$this->bps[$id]['state'], $this->bps[$id]['resolved_line']] = ['resolved', $bp['line']];
        }
    }

    /**
     * Reads the request or the command line of the script for the session title, at the first pause.
     */
    private function resolveTarget(): void
    {
        $value = function (string $name): ?Response {
            try {
                return $this->request('property_get', '-c 1 -m 2048 -n ' . Protocol::quote($name));
            } catch (DebuggerError $e) {
                $e->errorCode === 'xdebug_error' or throw $e;
                return null;
            }
        };

        try {
            $method = $value("\$_SERVER['REQUEST_METHOD']")?->properties()[0]['value'] ?? '';
            if ($method !== '') {
                $uri = $value("\$_SERVER['REQUEST_URI']")?->properties()[0]['value'] ?? '';
                [$kind, $target] = ['http', \trim("$method $uri")];
            } else {
                $argv = $value("\$_SERVER['argv']")?->properties()[0]['children'] ?? [];
                [$kind, $target] = ['cli', \implode(' ', \array_map(static fn(array $a): string => (string) ($a['value'] ?? ''), $argv))];
            }
        } catch (DebuggerError) {
            // Not paused any more: tried again at the next pause
            return;
        }

        if ($this->targetDone) {
            return;
        }
        [$this->kind, $this->target, $this->targetDone] = [$kind, $target, true];
        $this->debugger->emit('session.status', $this->statusEvent());
    }

    /**
     * @throws DebuggerError
     */
    private function command(string $name, string $args = '', ?string $data = null): string
    {
        \in_array($name, self::READS, true) or throw DebuggerError::badRequest("Command `$name` is not allowed.");
        \str_contains($args, "\0") and throw DebuggerError::badRequest('Arguments must not contain NUL.');

        return $this->exclusive(fn(): string => $this->exchange($name, $args, $data));
    }

    /**
     * Runs exchanges of the UI or an agent with no other read in between.
     *
     * @template T
     *
     * @param \Closure(): T $exchanges
     *
     * @return T
     *
     * @throws DebuggerError
     */
    private function exclusive(\Closure $exchanges): mixed
    {
        $timer = new Timer($this->timeout);
        while ($this->busy) {
            $timer->isReady() and throw DebuggerError::timeout();
            self::suspend();
        }
        $this->busy = true;
        try {
            $this->touch();
            $this->assertPaused();

            return $exchanges();
        } finally {
            $this->busy = false;
        }
    }

    /**
     * A command of the session itself, as a read for the title.
     *
     * @throws DebuggerError
     * @throws EngineError
     */
    private function request(string $name, string $args): Response
    {
        $this->assertPaused();

        return self::ok($this->exchange($name, $args, null));
    }

    /**
     * A command of the session itself that may come before the first pause, as setting a breakpoint.
     *
     * @throws DebuggerError
     * @throws EngineError
     */
    private function ownCall(string $command): Response
    {
        if (!$this->canSend()) {
            $this->bpDirty = true;
            throw DebuggerError::notPaused($this->state());
        }
        [$name, $args] = \explode(' ', $command, 2) + [1 => ''];

        return self::ok($this->exchange($name, $args, null));
    }

    /**
     * @throws DebuggerError
     */
    private function exchange(string $name, string $args, ?string $data): string
    {
        $tid = ++$this->nextTid;
        $this->pending[$tid] = ['kind' => 'reply', 'cont' => false];
        $this->engine->send(Protocol::command($name, $tid, $args, $data) . "\0");

        $timer = new Timer($this->timeout);
        while (!isset($this->replies[$tid])) {
            if ($timer->isReady()) {
                unset($this->pending[$tid]);
                throw DebuggerError::timeout();
            }
            self::suspend();
        }
        $body = $this->replies[$tid];
        unset($this->replies[$tid]);
        $body === false and throw DebuggerError::notPaused($this->state());

        return $body;
    }

    /**
     * Sends a command of the session without waiting for the reply.
     */
    private function sendOwn(string $command, bool $cont): void
    {
        if ($this->closed) {
            return;
        }
        [$name, $args] = \explode(' ', $command, 2) + [1 => ''];
        $tid = ++$this->nextTid;
        $this->pending[$tid] = ['kind' => 'own', 'cont' => $cont];
        $cont and $this->running();
        $this->engine->send(Protocol::command($name, $tid, $args) . "\0") or $this->detaching = $this->idleRelease = false;
    }

    private function canSend(): bool
    {
        return !$this->closed && $this->conts === 0 && ($this->status === 'break' || $this->status === 'starting');
    }

    /**
     * @throws DebuggerError
     */
    private function assertPaused(): void
    {
        !$this->closed && $this->status === 'break' && $this->conts === 0
            or throw DebuggerError::notPaused($this->state());
    }

    /**
     * A command of the UI or an agent: the idle guard waits again.
     */
    private function touch(): void
    {
        if ($this->driver !== self::DRIVER_HERE || $this->status !== 'break' || $this->conts > 0 || $this->idleDeadline === null) {
            return;
        }
        $this->armIdle();
        $deadline = (float) $this->idleDeadline;
        if ($deadline - ($this->idleSent ?? 0.0) >= 5.0) {
            $this->idleSent = $deadline;
            $this->debugger->emit('session.idle', ['id' => $this->id, 'idle_deadline' => self::timestamp($deadline)]);
        }
    }

    private function armIdle(): void
    {
        $this->driver === self::DRIVER_HERE && $this->idleTimeout > 0 && !$this->closed
            and $this->idleDeadline = \microtime(true) + $this->idleTimeout;
    }

    private function ran(string $uri): void
    {
        if ($uri === '' || isset($this->ran[$uri])) {
            return;
        }
        $this->ran[$uri] = true;
        \count($this->ran) > self::MAX_RAN_FILES and \array_shift($this->ran);
    }

    private function toIde(string $body): void
    {
        $this->ide?->send(Protocol::encodePacket($body));
    }

    private function task(\Closure $code): void
    {
        $this->tasks[] = new \Fiber($code);
    }
}
