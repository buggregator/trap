<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\XDebug\Frontend;

use Buggregator\Trap\Module\XDebug\Dbgp\Protocol;
use Buggregator\Trap\Module\XDebug\Debugger;
use Buggregator\Trap\Module\XDebug\DebuggerError;
use Buggregator\Trap\Module\XDebug\Session;
use Buggregator\Trap\Server\Config\TcpPorts;
use Buggregator\Trap\Server\Handler\Router\Attribute\QueryParam;
use Buggregator\Trap\Server\Handler\Router\Attribute\RegexpRoute;
use Buggregator\Trap\Server\Handler\Router\Attribute\StaticRoute;
use Buggregator\Trap\Server\Handler\Router\Method;
use Buggregator\Trap\Support\Json;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;

/**
 * The Xdebug page of the UI, under `/api/xdebug`. Errors are `{error, code, state?}` with an HTTP status;
 * the codes the page reacts to: `not_paused`, `eval_disabled`, `not_found`, `xdebug_error`, `timeout`,
 * `session_live`, `ide_attached`.
 *
 * @internal
 */
final class ApiController
{
    /** Lines of source a request reads at most */
    private const MAX_SOURCE_LINES = 2000;

    /** Bytes of a value `property` reads */
    private const MAX_PROPERTY_DATA = 1 << 20;

    private const CONTROLS = [
        'run' => 'run',
        'step-into' => 'step_into',
        'step-over' => 'step_over',
        'step-out' => 'step_out',
        'stop' => 'stop',
        'detach' => 'detach',
    ];

    public function __construct(
        private readonly Debugger $debugger,
        private readonly TcpPorts $tcp,
    ) {}

    /**
     * Asked first by the page: with the debugger off, the menu has no Xdebug item.
     */
    #[StaticRoute(Method::Get, 'api/xdebug')]
    public function status(): array
    {
        $config = $this->debugger->config;

        return [
            'enabled' => $this->debugger->isEnabled(),
            'addr' => $this->debugger->address($this->tcp->host),
            'ide_addr' => $config->ideAddr,
            'allow_eval' => $config->allowEval,
            'allow_control' => $config->allowControl,
        ] + $this->debugger->settings();
    }

    #[StaticRoute(Method::Put, 'api/xdebug/settings')]
    public function settings(mixed $mode = null, mixed $pause_on_first_line = null): ResponseInterface|array
    {
        return $this->guard(function () use ($mode, $pause_on_first_line): array {
            ($mode === null || \is_string($mode)) && ($pause_on_first_line === null || \is_bool($pause_on_first_line))
                && ($mode !== null || $pause_on_first_line !== null)
                or throw DebuggerError::badRequest('Body must be {"mode": "proxy|auto|standalone", "pause_on_first_line": bool}.');
            $this->debugger->changeSettings($mode, $pause_on_first_line);

            return $this->debugger->settings();
        });
    }

    #[StaticRoute(Method::Get, 'api/xdebug/sessions')]
    public function sessions(): ResponseInterface|array
    {
        return $this->guard(fn(): array => \array_map(
            static fn(Session $s): array => $s->info(),
            $this->debugger->sessions(),
        ));
    }

    #[RegexpRoute(Method::Delete, '#^api/xdebug/sessions/(?<id>[^/]++)$#')]
    public function dismiss(string $id): ResponseInterface
    {
        return $this->guard(function () use ($id): ResponseInterface {
            $this->debugger->dismiss($id);
            return new Response(204);
        });
    }

    #[RegexpRoute(Method::Get, '#^api/xdebug/sessions/(?<id>[^/]++)/stack$#')]
    public function stack(string $id): ResponseInterface|array
    {
        return $this->guard(function () use ($id): array {
            $frames = [];
            foreach ($this->session($id)->read('stack_get')->stack() as $frame) {
                $this->debugger->seeFile($frame['file']);
                $frames[] = \array_filter($frame, static fn(mixed $v): bool => $v !== '')
                    + ['vendor' => \str_contains($frame['file'], '/vendor/')];
            }

            return $frames;
        });
    }

    #[RegexpRoute(Method::Get, '#^api/xdebug/sessions/(?<id>[^/]++)/contexts$#')]
    public function contexts(string $id, #[QueryParam] string $frame = '0'): ResponseInterface|array
    {
        return $this->guard(fn(): array => $this->session($id)
            ->read('context_names', '-d ' . self::number('frame', $frame))
            ->contexts());
    }

    #[RegexpRoute(Method::Get, '#^api/xdebug/sessions/(?<id>[^/]++)/variables$#')]
    public function variables(
        string $id,
        #[QueryParam] string $frame = '0',
        #[QueryParam] string $context = '0',
    ): ResponseInterface|array {
        return $this->guard(fn(): array => $this->session($id)
            ->read('context_get', \sprintf('-d %d -c %d', self::number('frame', $frame), self::number('context', $context)))
            ->properties());
    }

    #[RegexpRoute(Method::Get, '#^api/xdebug/sessions/(?<id>[^/]++)/property$#')]
    public function property(
        string $id,
        #[QueryParam] string $name = '',
        #[QueryParam] string $frame = '0',
        #[QueryParam] string $context = '0',
        #[QueryParam] string $page = '0',
    ): ResponseInterface|array {
        return $this->guard(function () use ($id, $name, $frame, $context, $page): array {
            \trim($name) !== '' && !\str_contains($name, "\0")
                or throw DebuggerError::badRequest('name is required.');
            $args = \sprintf(
                '-d %d -c %d -m %d -p %d -n %s',
                self::number('frame', $frame),
                self::number('context', $context),
                self::MAX_PROPERTY_DATA,
                self::number('page', $page),
                Protocol::quote($name),
            );

            return $this->session($id)->read('property_get', $args)->properties()[0]
                ?? throw DebuggerError::notFound("Property $name not found.");
        });
    }

    #[RegexpRoute(Method::Get, '#^api/xdebug/sessions/(?<id>[^/]++)/source$#')]
    public function source(
        string $id,
        #[QueryParam] string $file = '',
        #[QueryParam] string $begin = '1',
        #[QueryParam] string $end = '0',
    ): ResponseInterface|array {
        return $this->guard(function () use ($id, $file, $begin, $end): array {
            $file !== '' && !\str_contains($file, "\0") or throw DebuggerError::badRequest('file is required.');
            $from = \max(1, self::number('begin', $begin));
            $to = self::number('end', $end);
            $to < $from || $to - $from > self::MAX_SOURCE_LINES and $to = $from + self::MAX_SOURCE_LINES;
            $text = \rtrim($this->session($id)->read('source', \sprintf('-f %s -b %d -e %d', Protocol::quote($file), $from, $to))->text(), "\n");

            $lines = [];
            foreach ($text === '' ? [] : \explode("\n", $text) as $i => $line) {
                $lines[] = ['line' => $from + $i, 'text' => \rtrim($line, "\r")];
            }

            return ['file' => $file, 'begin' => $from, 'lines' => $lines];
        });
    }

    #[RegexpRoute(Method::Get, '#^api/xdebug/sessions/(?<id>[^/]++)/breakpoints$#')]
    public function sessionBreakpoints(string $id): ResponseInterface|array
    {
        return $this->guard(function () use ($id): array {
            $session = $this->session($id);
            if ($session->driver === Session::DRIVER_HERE) {
                return $session->breakpointStates($this->debugger->breakpoints->all());
            }

            return \array_map(
                static fn(array $bp): array => \array_filter($bp, static fn(mixed $v, string $k): bool => $k === 'hit_count' || ($v !== '' && $v !== 0), \ARRAY_FILTER_USE_BOTH),
                \array_map(static function (array $bp): array {
                    unset($bp['resolved']);
                    return $bp;
                }, $session->read('breakpoint_list')->breakpoints()),
            );
        });
    }

    #[RegexpRoute(Method::Get, '#^api/xdebug/sessions/(?<id>[^/]++)/agent-log$#')]
    public function agentLog(string $id): ResponseInterface|array
    {
        return $this->guard(fn(): array => $this->session($id)->agentLog());
    }

    #[RegexpRoute(Method::Post, '#^api/xdebug/sessions/(?<id>[^/]++)/eval$#')]
    public function evaluate(string $id, mixed $expression = null): ResponseInterface|array
    {
        return $this->guard(function () use ($id, $expression): array {
            $this->debugger->allowEval() or throw DebuggerError::evalDisabled();
            \is_string($expression) && \trim($expression) !== ''
                or throw DebuggerError::badRequest('Body must be {"expression": "..."}.');

            return $this->session($id)->evaluate($expression)->properties()[0]
                ?? ['name' => '', 'type' => 'null'];
        });
    }

    #[RegexpRoute(Method::Post, '#^api/xdebug/sessions/(?<id>[^/]++)/(?<command>run|step-into|step-over|step-out|stop|detach)$#')]
    public function control(string $id, string $command): ResponseInterface|array
    {
        return $this->guard(function () use ($id, $command): array {
            $session = $this->session($id);
            $session->continue(self::CONTROLS[$command], Session::BY_UI);

            return $session->info();
        });
    }

    #[StaticRoute(Method::Get, 'api/xdebug/breakpoints')]
    public function breakpoints(): ResponseInterface|array
    {
        return $this->guard(fn(): array => $this->debugger->breakpoints->all());
    }

    #[StaticRoute(Method::Post, 'api/xdebug/breakpoints')]
    public function addBreakpoint(mixed ...$body): ResponseInterface
    {
        return $this->guard(function () use ($body): ResponseInterface {
            $this->assertCondition($body);
            $bp = $this->debugger->breakpoints->add($body, 'ui');
            $this->debugger->breakpointsChanged();

            return self::json(201, $bp);
        });
    }

    #[RegexpRoute(Method::Patch, '#^api/xdebug/breakpoints/(?<bp>\d++)$#')]
    public function updateBreakpoint(mixed ...$body): ResponseInterface|array
    {
        return $this->guard(function () use ($body): array {
            $id = (int) ($body['bp'] ?? 0);
            unset($body['bp']);
            $this->assertCondition($body);
            $bp = $this->debugger->breakpoints->update($id, \array_intersect_key($body, ['enabled' => 1, 'condition' => 1]));
            $this->debugger->breakpointsChanged();

            return $bp;
        });
    }

    #[RegexpRoute(Method::Delete, '#^api/xdebug/breakpoints/(?<bp>\d++)$#')]
    public function removeBreakpoint(string $bp): ResponseInterface
    {
        return $this->guard(function () use ($bp): ResponseInterface {
            $this->debugger->breakpoints->delete((int) $bp);
            $this->debugger->breakpointsChanged();

            return new Response(204);
        });
    }

    #[StaticRoute(Method::Get, 'api/xdebug/files')]
    public function files(#[QueryParam] string $q = '', #[QueryParam] string $limit = '20'): ResponseInterface|array
    {
        return $this->guard(function () use ($q, $limit): array {
            $max = \ctype_digit($limit) && (int) $limit > 0 && (int) $limit <= 200 ? (int) $limit : 20;

            return $this->debugger->files($q, $max);
        });
    }

    /**
     * @throws DebuggerError
     */
    private static function number(string $name, string $value): int
    {
        $value === '' and $value = '0';
        \ctype_digit($value) or throw DebuggerError::badRequest("$name must be a non-negative integer.");

        return (int) $value;
    }

    private static function json(int $status, mixed $data): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'application/json'], Json::encode($data));
    }

    /**
     * A condition is PHP code the script runs: only with eval allowed.
     *
     * @throws DebuggerError
     */
    private function assertCondition(array $body): void
    {
        /** @var mixed $condition */
        $condition = $body['condition'] ?? null;
        \is_string($condition) && \trim($condition) !== '' && !$this->debugger->allowEval()
            and throw DebuggerError::evalDisabled('A breakpoint condition');
    }

    /**
     * @throws DebuggerError
     */
    private function session(string $id): Session
    {
        return $this->debugger->session(\rawurldecode($id));
    }

    /**
     * @template T of array|ResponseInterface
     *
     * @param \Closure(): T $action
     *
     * @return T|ResponseInterface
     */
    private function guard(\Closure $action): array|ResponseInterface
    {
        try {
            $this->debugger->isEnabled() or throw DebuggerError::off();

            return $action();
        } catch (DebuggerError $e) {
            return self::json($e->status, $e->toArray());
        }
    }
}
