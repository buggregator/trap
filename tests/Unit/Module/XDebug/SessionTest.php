<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\XDebug;

use Buggregator\Trap\Module\XDebug\Breakpoints;
use Buggregator\Trap\Module\XDebug\Config\Debugger as Config;
use Buggregator\Trap\Module\XDebug\Connection;
use Buggregator\Trap\Module\XDebug\Debugger;
use Buggregator\Trap\Module\XDebug\DebuggerError;
use Buggregator\Trap\Module\XDebug\Session;
use Buggregator\Trap\Tests\Unit\Module\XDebug\Stub\FakeEngine;
use Buggregator\Trap\Tests\Unit\Module\XDebug\Stub\FakeIde;
use Buggregator\Trap\Tests\Unit\Module\XDebug\Stub\Harness;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Covers(Session::class)]
#[Covers(Connection::class)]
#[Covers(Debugger::class)]
#[Test]
final class SessionTest
{
    private const FILE = 'file:///app/a.php';

    public function testDrivesSessionHereToFirstLine(): void
    {
        $h = self::harness(pauseOnFirstLine: true);

        $h->connect();
        $h->pump(30);

        $session = $h->session();
        Assert::same($session->driver, Session::DRIVER_HERE);
        Assert::same($session->state(), 'break');
        Assert::same($session->location(), [self::FILE, 3]);
        $names = \array_map(static fn(string $c): string => \explode(' ', $c)[0], $h->engine->commands);
        Assert::same(\array_slice($names, 0, 6), ['feature_set', 'feature_set', 'feature_set', 'feature_set', 'feature_set', 'step_into']);
        Assert::same($session->info()['kind'], 'http');
        Assert::same($session->info()['target'], 'GET /users?id=1');
        Assert::same($session->info()['idekey'], 'PHPSTORM');
        Assert::same(\array_slice($h->eventNames(), 0, 3), ['session.created', 'session.status', 'session.status']);
        Assert::notSame($session->info()['idle_deadline'], null);
    }

    public function testRunsToEndWithoutPause(): void
    {
        $h = self::harness();

        $h->connect();
        $h->pump(30);

        Assert::contains($h->engine->commands, 'run -i 6');
    }

    public function testCachesReadsOfOnePause(): void
    {
        $h = self::harness(pauseOnFirstLine: true);
        $h->connect();
        $h->pump(30);

        $first = $h->call(static fn() => $h->session()->read('stack_get')->stack());
        $second = $h->call(static fn() => $h->session()->read('stack_get')->stack());

        Assert::same($first, $second);
        Assert::same($first[0]['where'], '{main}');
        Assert::count(\array_filter($h->engine->commands, static fn(string $c): bool => \str_starts_with($c, 'stack_get')), 1);
    }

    public function testStopsAfterScriptEnds(): void
    {
        $h = self::harness(pauseOnFirstLine: true);
        $h->engine->responders['run'] = static fn(array $c): string => FakeEngine::response('run', $c['tid'], ' status="stopping" reason="ok"');
        $h->engine->responders['stop'] = static function (array $c) use ($h): string {
            $h->engine->close();
            return FakeEngine::response('stop', $c['tid'], ' status="stopped" reason="ok"');
        };
        $h->connect();
        $h->pump(30);

        $h->call(static fn() => $h->session()->continue('run', Session::BY_UI));
        $h->pump(30);

        Assert::true($h->session()->isClosed());
        Assert::same($h->session()->info()['closed_reason'], Session::CLOSED_STOPPED);
        Assert::same($h->session()->state(), 'stopped');
        Assert::same($h->session()->info()['last_continuation_by'], 'ui');
        Assert::contains($h->eventNames(), 'session.closed');
        Assert::false($h->isRunning());
    }

    public function testRefusesReadsWhileRunning(): void
    {
        $h = self::harness(pauseOnFirstLine: true);
        $h->engine->responders['step_over'] = static fn(): string => '';
        $h->connect();
        $h->pump(30);
        $h->call(static fn() => $h->session()->continue('step_over', Session::BY_UI));

        $error = $h->call(static function () use ($h): ?DebuggerError {
            try {
                $h->session()->read('stack_get');
            } catch (DebuggerError $e) {
                return $e;
            }
            return null;
        });

        Assert::same($error?->errorCode, 'not_paused');
        Assert::same($error->state, 'running');
    }

    public function testRelaysToIdeWithItsTransactionIds(): void
    {
        $h = self::harness();
        $ide = new FakeIde();
        $h->engine->responders['step_into'] = static fn(array $c): string => FakeEngine::breakAt('step_into', $c['tid'], self::FILE, 3);

        $h->connect($ide);
        $ide->command('step_into -i 77');
        $h->pump(10);

        $session = $h->session();
        Assert::same($session->driver, Session::DRIVER_IDE);
        Assert::string($ide->packets[0])->contains('<init');
        Assert::same($h->engine->commands[0], 'step_into -i 1');
        Assert::string($ide->packets[1])->contains('transaction_id="77"');
        Assert::same($session->state(), 'break');
        Assert::true($session->info()['ide_connected']);
    }

    public function testRefusesControlOfIdeSession(): void
    {
        $h = self::harness();
        $ide = new FakeIde();
        $h->engine->responders['step_into'] = static fn(array $c): string => FakeEngine::breakAt('step_into', $c['tid'], self::FILE, 3);
        $h->connect($ide);
        $ide->command('step_into -i 77');
        $h->pump(10);

        $error = $h->call(static function () use ($h): ?DebuggerError {
            try {
                $h->session()->continue('run', Session::BY_UI);
            } catch (DebuggerError $e) {
                return $e;
            }
            return null;
        });

        Assert::same($error?->errorCode, 'ide_attached');
    }

    public function testDetachesWhenIdeLeaves(): void
    {
        $h = self::harness();
        $ide = new FakeIde();
        $h->connect($ide);

        $ide->close();
        $h->pump(5);

        Assert::same($h->session()->info()['closed_reason'], Session::CLOSED_IDE);
        Assert::same($h->session()->state(), 'detached');
        Assert::string(\end($h->engine->commands))->startsWith('detach -i');
    }

    public function testProxyModeWithoutIdeLetsScriptRunOn(): void
    {
        $h = self::harness(mode: Config::MODE_PROXY);

        $h->connect();

        Assert::same($h->session()->info()['closed_reason'], Session::CLOSED_IDE_UNREACHABLE);
        Assert::string($h->engine->commands[0])->startsWith('detach -i');
        Assert::true($h->engine->closed);
    }

    public function testSendsSavedBreakpointsAndTakesEarlyResolve(): void
    {
        $h = self::harness();
        $h->debugger->breakpoints->add(['file' => '/app/a.php', 'line' => 7], 'ui');
        $h->debugger->breakpoints->add(['exception' => 'RuntimeException'], 'ui');
        $h->engine->responders['breakpoint_set'] = static function (array $c) use ($h): string {
            if (\str_contains($c['args'], '-t line')) {
                // Xdebug notifies before it answers
                $h->engine->emit('<?xml version="1.0"?><notify xmlns="urn:debugger_protocol_v1" name="breakpoint_resolved"><breakpoint id="9001" lineno="8" resolved="resolved"/></notify>');
                return FakeEngine::response('breakpoint_set', $c['tid'], ' id="9001" resolved="unresolved"');
            }
            return FakeEngine::response('breakpoint_set', $c['tid'], ' id="9002"');
        };

        $h->connect();
        $h->pump(40);

        Assert::contains($h->engine->commands, 'breakpoint_set -i 6 -t line -f file:///app/a.php -n 7');
        Assert::contains($h->engine->commands, 'breakpoint_set -i 7 -t exception -x RuntimeException');
        $states = $h->session()->breakpointStates($h->debugger->breakpoints->all());
        Assert::same($states[0]['state'], 'resolved');
        Assert::same($states[0]['resolved_line'], 8);
        Assert::same($states[1]['state'], 'resolved');
        Assert::contains($h->eventNames(), 'breakpoint.resolved');
    }

    public function testReadsHitCountsAndResolvedLinesWhilePaused(): void
    {
        $h = self::harness(pauseOnFirstLine: true);
        $h->debugger->breakpoints->add(['file' => '/app/a.php', 'line' => 7], 'ui');
        $h->engine->responders['breakpoint_set'] = static fn(array $c): string => FakeEngine::response('breakpoint_set', $c['tid'], ' id="9001" resolved="unresolved"');
        $h->engine->responders['breakpoint_list'] = static fn(array $c): string => FakeEngine::response(
            'breakpoint_list',
            $c['tid'],
            '',
            '<breakpoint type="line" resolved="resolved" filename="file:///app/a.php" lineno="8" state="enabled" hit_count="2" id="9001"></breakpoint>',
        );
        $h->connect();
        $h->pump(40);

        $states = $h->call(static fn(): array => $h->session()->breakpointStates($h->debugger->breakpoints->all()));

        Assert::same($states[0]['hit_count'], 2);
        Assert::same($states[0]['resolved_line'], 8);
        Assert::same($states[0]['state'], 'resolved');
    }

    public function testKeepsExceptionOfPauseAndReadsSourceFromHost(): void
    {
        $path = \tempnam(\sys_get_temp_dir(), 'xdbg');
        \file_put_contents($path, "<?php\n\$a = 1;\nthrow new \\DomainException('bad');\n");
        $uri = Breakpoints::fileUri(\str_replace('\\', '/', $path));
        $h = self::harness(pauseOnFirstLine: true);
        $h->engine->responders['step_into'] = static fn(array $c): string => FakeEngine::response(
            'step_into',
            $c['tid'],
            ' status="break" reason="ok"',
            "<xdebug:message filename=\"$uri\" lineno=\"3\" exception=\"DomainException\" code=\"7\"><![CDATA[bad]]></xdebug:message>",
        );
        $h->engine->responders['source'] = static fn(array $c): string => FakeEngine::response('source', $c['tid'], '', '<error code="100"><message><![CDATA[can not open file]]></message></error>');
        $h->engine->responders['step_over'] = static fn(): string => '';
        $h->connect();
        $h->pump(30);

        try {
            $exception = $h->session()->exception();
            $source = $h->call(static fn(): string => $h->session()->source($uri, 2, 3));
            $h->call(static fn() => $h->session()->continue('step_over', Session::BY_UI));
        } finally {
            \unlink($path);
        }

        Assert::same($exception, ['class' => 'DomainException', 'message' => 'bad', 'code' => '7']);
        Assert::same($source, "\$a = 1;\nthrow new \\DomainException('bad');\n");
        Assert::null($h->session()->exception());
    }

    public function testFailsEvalOnWarningOfExpression(): void
    {
        $h = self::harness(pauseOnFirstLine: true);
        $evals = [];
        $h->engine->responders['eval'] = static function (array $c) use (&$evals): string {
            $code = \base64_decode(\explode('-- ', $c['args'])[1]);
            $evals[] = $code;
            $field = static fn(string $name, string $value): string => "<property name=\"$name\" type=\"string\" encoding=\"base64\"><![CDATA[" . \base64_encode($value) . ']]></property>';
            $inner = $code === '\error_get_last()'
                ? '<property type="array" numchildren="4"><property name="type" type="int"><![CDATA[2]]></property>'
                    . $field('message', 'Undefined variable $x') . $field('file', 'xdebug://debug-eval') . '</property>'
                : '<property type="null"></property>';
            return FakeEngine::response('eval', $c['tid'], '', $inner);
        };
        $h->connect();
        $h->pump(30);

        $error = $h->call(static function () use ($h): ?DebuggerError {
            try {
                $h->session()->evaluate('$x // a comment');
            } catch (DebuggerError $e) {
                return $e;
            }
            return null;
        });

        Assert::same($evals, ['\error_clear_last()', "@(\$x // a comment\n)", '\error_get_last()']);
        Assert::same($error?->errorCode, 'eval_error');
        Assert::string($error->getMessage())->contains('Warning in the expression: Undefined variable $x');
    }

    public function testSkipsConditionWithoutEval(): void
    {
        $h = self::harness();
        $h->debugger->breakpoints->add(['file' => '/app/a.php', 'line' => 7, 'condition' => '$a > 1'], 'ui');

        $h->connect();
        $h->pump(40);

        Assert::same($h->session()->breakpointStates($h->debugger->breakpoints->all())[0]['state'], 'skipped');
    }

    public function testReleasesIdleSession(): void
    {
        $config = new Config();
        $config->idleTimeout = 1;
        $config->pauseOnFirstLine = true;
        $config->mode = Config::MODE_STANDALONE;
        $h = new Harness(self::engine(), $config);
        $h->engine->responders['detach'] = static function (array $c) use ($h): string {
            $h->engine->close();
            return FakeEngine::response('detach', $c['tid'], ' status="stopping"');
        };
        $h->connect();
        $h->pump(30);

        \usleep(1_050_000);
        $h->pump(10);

        Assert::same($h->session()->info()['closed_reason'], Session::CLOSED_IDLE);
    }

    public function testDismissesOnlyClosedSessions(): void
    {
        $h = self::harness(mode: Config::MODE_PROXY);
        $h->connect();

        $h->debugger->dismiss('1');

        Assert::same($h->debugger->sessions(), []);
    }

    public function testStopsWaitingWhenCancelled(): void
    {
        $h = self::harness(pauseOnFirstLine: true);
        $h->engine->responders['step_over'] = static fn(): string => '';
        $h->connect();
        $h->pump(30);
        $h->call(static fn() => $h->session()->continue('step_over', Session::BY_UI));
        $start = \microtime(true);

        $paused = $h->call(static fn(): bool => $h->session()->waitPause($h->session()->pauseSeq(), 5.0, static fn(): bool => true));

        Assert::false($paused);
        Assert::true(\microtime(true) - $start < 1.0);
    }

    public function testCapsLiveSessions(): void
    {
        $h = self::harness(mode: Config::MODE_STANDALONE);
        for ($i = 0; $i < Debugger::MAX_LIVE_SESSIONS; ++$i) {
            $h->debugger->start(new FakeEngine(), FakeEngine::INIT);
        }
        $engine = new FakeEngine();

        $session = $h->debugger->start($engine, FakeEngine::INIT);

        Assert::null($session);
        Assert::string($engine->commands[0])->startsWith('detach -i');
        Assert::count($h->debugger->sessions(), Debugger::MAX_LIVE_SESSIONS);
    }

    public function testAsksUnreachableIdeOnceInAWhile(): void
    {
        $h = self::harness();
        $tries = 0;
        $connect = static function () use (&$tries): null {
            ++$tries;
            return null;
        };

        $h->debugger->start(new FakeEngine(), FakeEngine::INIT, $connect);
        $h->debugger->start(new FakeEngine(), FakeEngine::INIT, $connect);

        Assert::same($tries, 1);
        Assert::same($h->debugger->session('2')->driver, Session::DRIVER_HERE);
    }

    private static function harness(bool $pauseOnFirstLine = false, string $mode = Config::MODE_AUTO): Harness
    {
        $config = new Config();
        $config->pauseOnFirstLine = $pauseOnFirstLine;
        $config->mode = $mode;

        return new Harness(self::engine(), $config);
    }

    /**
     * An engine of a web request that stops at line 3 after the first step.
     */
    private static function engine(): FakeEngine
    {
        $property = static fn(string $name, string $value): string => "<property name=\"$name\" type=\"string\" encoding=\"base64\"><![CDATA[" . \base64_encode($value) . ']]></property>';

        return new FakeEngine([
            'step_into' => static fn(array $c): string => FakeEngine::breakAt('step_into', $c['tid'], self::FILE, 3),
            'run' => static fn(array $c): string => '',
            'stack_get' => static fn(array $c): string => FakeEngine::response('stack_get', $c['tid'], '', '<stack where="{main}" level="0" type="file" filename="' . self::FILE . '" lineno="3"></stack>'),
            'property_get' => static fn(array $c): string => FakeEngine::response('property_get', $c['tid'], '', match (true) {
                \str_contains($c['args'], 'REQUEST_METHOD') => $property('m', 'GET'),
                \str_contains($c['args'], 'REQUEST_URI') => $property('u', '/users?id=1'),
                default => '',
            }),
        ]);
    }
}
