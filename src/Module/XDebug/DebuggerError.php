<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\XDebug;

/**
 * Why a debugger request failed: the `code` the UI and the agents react to, and its HTTP status.
 *
 * @internal
 */
final class DebuggerError extends \RuntimeException
{
    private function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        string $message,
        public readonly ?string $state = null,
    ) {
        parent::__construct($message);
    }

    public static function notPaused(?string $state = null): self
    {
        return new self(
            'not_paused',
            409,
            'The session is not paused: the script is running or has not stopped at a breakpoint yet.',
            $state,
        );
    }

    public static function ideAttached(string $state): self
    {
        return new self('ide_attached', 409, 'An IDE drives this session: step and continue in the IDE.', $state);
    }

    public static function notFound(string $message): self
    {
        return new self('not_found', 404, $message);
    }

    public static function sessionLive(): self
    {
        return new self('session_live', 409, 'The session is live: only a closed session can be dismissed.');
    }

    public static function timeout(): self
    {
        return new self('timeout', 504, 'Timed out waiting for Xdebug.');
    }

    public static function evalDisabled(string $what = 'Eval'): self
    {
        return new self('eval_disabled', 403, "$what runs PHP code in the script: it is off, see TRAP_XDEBUG_ALLOW_EVAL.");
    }

    public static function controlDisabled(): self
    {
        return new self('control_disabled', 403, 'Agents may not step or continue: see TRAP_XDEBUG_ALLOW_CONTROL.');
    }

    public static function engine(Dbgp\EngineError $e): self
    {
        return new self('xdebug_error', 422, $e->getMessage());
    }

    public static function badRequest(string $message): self
    {
        return new self('bad_request', 400, $message);
    }

    public static function forbidden(string $code, string $message): self
    {
        return new self($code, 403, $message);
    }

    public static function unsupportedMediaType(): self
    {
        return new self('unsupported_media_type', 415, 'Content-Type must be application/json.');
    }

    public static function off(): self
    {
        return new self('not_found', 404, 'The Xdebug debugger is off: start Trap with the --xdebug option.');
    }

    /**
     * @return array{error: string, code: string, state?: string}
     */
    public function toArray(): array
    {
        return ['error' => $this->getMessage(), 'code' => $this->errorCode]
            + ($this->state === null ? [] : ['state' => $this->state]);
    }
}
