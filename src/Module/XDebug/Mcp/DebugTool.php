<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\XDebug\Mcp;

use Buggregator\Trap\Module\Mcp\Caller;
use Buggregator\Trap\Module\Mcp\McpStatus;
use Buggregator\Trap\Module\Mcp\Tool;
use Buggregator\Trap\Module\XDebug\Debugger;
use Buggregator\Trap\Module\XDebug\DebuggerError;
use Buggregator\Trap\Module\XDebug\Session;

/**
 * A tool on an Xdebug session. Each call on a session is in its agent log: the UI shows what the agent read
 * and who stepped.
 *
 * @internal
 */
abstract class DebugTool implements Tool
{
    protected const SESSION_ID = [
        'type' => 'string',
        'description' => 'Session ID from `xdebug_sessions`; omit for the only paused session.',
    ];

    public function __construct(
        protected readonly Debugger $debugger,
        private readonly McpStatus $mcp,
        protected readonly Caller $caller = new Caller(),
    ) {}

    #[\Override]
    public function outputSchema(): ?array
    {
        return null;
    }

    #[\Override]
    public function annotations(): array
    {
        return ['readOnlyHint' => true, 'openWorldHint' => false];
    }

    /**
     * The session as the list shows it, with the exception it is paused at.
     *
     * @return array<array-key, mixed>
     */
    protected static function info(Session $session): array
    {
        return $session->info() + self::exception($session);
    }

    /**
     * @return array{exception?: array{class: string, message: string, code?: string}}
     */
    protected static function exception(Session $session): array
    {
        $exception = $session->exception();

        return $exception === null ? [] : ['exception' => $exception];
    }

    /**
     * Where a paused session is: file, line, function, the source line and the exception. A read that fails
     * leaves its field out: the location is the answer.
     *
     * @return array<array-key, mixed>
     */
    protected static function pause(Session $session): array
    {
        [$file, $line] = $session->location();
        $pause = ['file' => $file, 'line' => $line];
        try {
            $pause['where'] = $session->read('stack_get')->stack()[0]['where'] ?? '';
            $pause['source_line'] = \trim($session->source($file, $line, $line));
        } catch (DebuggerError) {
        }

        return $pause + self::exception($session);
    }

    protected static function int(array $arguments, string $name, int $default): int
    {
        /** @var mixed $value */
        $value = $arguments[$name] ?? $default;
        \is_int($value) && $value >= 0 or throw new \InvalidArgumentException("`$name` must be a whole number.");

        return $value;
    }

    /**
     * Runs the action on the session the arguments name, as an agent call of it.
     *
     * @param \Closure(Session): array{array<array-key, mixed>, string} $action The result and its summary for the log.
     *
     * @return array<array-key, mixed>
     */
    protected function onSession(array $arguments, \Closure $action): array
    {
        $this->debugger->isEnabled() or throw new \RuntimeException(DebuggerError::off()->getMessage());
        /** @var mixed $id */
        $id = $arguments['session_id'] ?? null;
        $session = $this->session(\is_string($id) ? $id : null);

        $args = $arguments;
        unset($args['session_id']);
        $args = $args === [] ? '' : \substr((string) \json_encode($args, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE), 0, 120);
        $started = \microtime(true);
        $session->agentStarted($this->name(), $args);
        try {
            [$result, $summary] = $action($session);
            $session->agentDone($this->name(), $args, $started, true, $summary);

            return $result;
        } catch (DebuggerError $e) {
            $session->agentDone($this->name(), $args, $started, false, $e->getMessage());
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }
    }

    protected function agent(): string
    {
        return $this->mcp->clientName() ?: 'agent';
    }

    /**
     * @throws \RuntimeException
     */
    protected function assertControl(): void
    {
        $this->debugger->config->allowControl or throw new \RuntimeException(DebuggerError::controlDisabled()->getMessage());
    }

    /**
     * @throws \RuntimeException
     */
    private function session(?string $id): Session
    {
        try {
            return $this->debugger->pick($id);
        } catch (DebuggerError $e) {
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }
    }
}
