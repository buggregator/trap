<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\XDebug\Mcp;

use Buggregator\Trap\Module\Mcp\McpStatus;
use Buggregator\Trap\Module\XDebug\Config\Debugger as Config;
use Buggregator\Trap\Module\XDebug\Mcp\BreakpointsTool;
use Buggregator\Trap\Module\XDebug\Mcp\Control;
use Buggregator\Trap\Module\XDebug\Mcp\DebugTool;
use Buggregator\Trap\Module\XDebug\Mcp\Evaluate;
use Buggregator\Trap\Module\XDebug\Mcp\Location;
use Buggregator\Trap\Module\XDebug\Mcp\Sessions;
use Buggregator\Trap\Module\XDebug\Mcp\Stack;
use Buggregator\Trap\Module\XDebug\Mcp\Variables;
use Buggregator\Trap\Module\XDebug\Session;
use Buggregator\Trap\Tests\Unit\Module\XDebug\Stub\FakeEngine;
use Buggregator\Trap\Tests\Unit\Module\XDebug\Stub\Harness;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Covers(DebugTool::class)]
#[Covers(Sessions::class)]
#[Covers(Location::class)]
#[Covers(Stack::class)]
#[Covers(Variables::class)]
#[Covers(Evaluate::class)]
#[Covers(Control::class)]
#[Covers(BreakpointsTool::class)]
#[Test]
final class DebugToolsTest
{
    public function testAgentReadsAndStepsAndIsLogged(): void
    {
        [$h, $mcp] = self::paused();
        $mcp->clientNamed('claude-code');

        $location = $h->call(static fn() => (new Location($h->debugger, $mcp))->call([]));
        $vars = $h->call(static fn() => (new Variables($h->debugger, $mcp))->call(['session_id' => '1']));
        $step = $h->call(static fn() => (new Control($h->debugger, $mcp))->call(['command' => 'step_over', 'wait' => 1]));

        Assert::same($location['line'], 3);
        Assert::string($location['source'])->contains('>3: $c = 3;');
        Assert::same($vars['variables'][0]['name'], '$a');
        Assert::same($step['paused'], true);
        Assert::same($step['line'], 4);
        $session = $h->session()->info();
        Assert::same($session['agent_calls'], 3);
        Assert::same($session['last_continuation_by'], 'agent');
        Assert::same($session['last_continuation_agent'], 'claude-code');
        Assert::same(\array_column($h->session()->agentLog(), 'tool'), ['xdebug_location', 'xdebug_variables', 'xdebug_continue']);
        Assert::contains($h->eventNames(), 'agent.call.started');
    }

    public function testStepReturnsFunctionAndSourceLine(): void
    {
        [$h, $mcp] = self::paused();

        $step = $h->call(static fn() => (new Control($h->debugger, $mcp))->call(['command' => 'step_over', 'wait' => 1]));

        Assert::same($step['where'], 'run');
        Assert::same($step['source_line'], '$d = 4;');
        Assert::false(isset($step['exception']));
    }

    public function testLimitsStack(): void
    {
        [$h, $mcp] = self::paused();

        $limited = $h->call(static fn() => (new Stack($h->debugger, $mcp))->call(['limit' => 1]));
        $all = $h->call(static fn() => (new Stack($h->debugger, $mcp))->call(['limit' => 0]));

        Assert::count($limited['frames'], 1);
        Assert::same($limited['total'], 2);
        Assert::string($limited['hint'])->contains('1 outer frames');
        Assert::count($all['frames'], 2);
        Assert::false(isset($all['hint']));
    }

    public function testListsBreakpointsWithTheirStateInSessions(): void
    {
        [$h, $mcp] = self::paused();
        $tool = new BreakpointsTool($h->debugger, $mcp);
        $h->call(static fn() => $tool->call(['action' => 'set', 'file' => '/app/a.php', 'line' => 3]));
        $h->pump(30);

        $list = $h->call(static fn() => $tool->call([]));

        Assert::same($list['breakpoints'][0]['sessions'], [['session_id' => '1', 'session_state' => 'break', 'state' => 'resolved', 'resolved_line' => 4, 'hit_count' => 1]]);
    }

    public function testKeepsHitsOfLastSessionAfterItEnds(): void
    {
        [$h, $mcp] = self::paused();
        $tool = new BreakpointsTool($h->debugger, $mcp);
        $h->call(static fn() => $tool->call(['action' => 'set', 'file' => '/app/a.php', 'line' => 3]));
        $h->pump(30);
        $h->engine->responders['step_over'] = static fn(array $c): string => FakeEngine::breakAt('step_over', $c['tid'], 'file:///app/a.php', 4);
        $h->call(static fn() => (new Control($h->debugger, $mcp))->call(['command' => 'step_over', 'wait' => 1]));
        $h->pump(30);
        $h->engine->close();
        $h->pump(10);

        $list = $tool->call([]);

        Assert::same($list['breakpoints'][0]['sessions'][0]['session_state'], 'stopped');
        Assert::same($list['breakpoints'][0]['sessions'][0]['hit_count'], 1);
    }

    public function testStepOutReturnsReturnValue(): void
    {
        [$h, $mcp] = self::paused();
        $h->engine->responders['step_out'] = static fn(array $c): string => FakeEngine::response(
            'step_out',
            $c['tid'],
            ' status="break" reason="ok"',
            '<xdebug:message filename="file:///app/index.php" lineno="9"></xdebug:message>'
            . '<xdebug:return_value><property type="int"><![CDATA[42]]></property></xdebug:return_value>',
        );

        $step = $h->call(static fn() => (new Control($h->debugger, $mcp))->call(['command' => 'step_out', 'wait' => 1]));

        // Xdebug keeps `run`, which returned, on top of the stack: the paused line is in the frame of the caller
        Assert::same($step['line'], 9);
        Assert::same($step['where'], '{main}');
        Assert::same($step['return_value'], ['name' => '', 'type' => 'int', 'value' => '42']);
        Assert::same($step['returned_from'], 'run');
    }

    public function testLocationKeepsPlaceWhenSourceIsUnreadable(): void
    {
        [$h, $mcp] = self::paused();
        $h->engine->responders['source'] = static fn(array $c): string => FakeEngine::response('source', $c['tid'], '', '<error code="100"><message><![CDATA[can not open file]]></message></error>');

        $location = $h->call(static fn() => (new Location($h->debugger, $mcp))->call([]));

        Assert::same($location['line'], 3);
        Assert::same($location['where'], 'run');
        Assert::string($location['source_unavailable'])->contains('can not open file');
    }

    public function testListsLastClosedSessionByCloseTime(): void
    {
        $config = new Config();
        $config->mode = Config::MODE_STANDALONE;
        $h = new Harness(new FakeEngine(), $config);
        $mcp = new McpStatus();
        $h->debugger->breakpoints->add(['file' => '/app/a.php', 'line' => 3], 'mcp');
        $first = $h->debugger->start(new FakeEngine(), FakeEngine::INIT);
        $second = $h->debugger->start(new FakeEngine(), FakeEngine::INIT);
        $second?->close(Session::CLOSED_ENGINE);
        \usleep(2000);
        $first?->close(Session::CLOSED_ENGINE);

        $list = (new BreakpointsTool($h->debugger, $mcp))->call([]);

        Assert::same(\array_column($list['breakpoints'][0]['sessions'], 'session_id'), [$first?->id]);
    }

    public function testGatesEvalAndControl(): void
    {
        $config = new Config();
        $config->allowControl = false;
        [$h, $mcp] = self::paused($config);

        $eval = self::error(static fn() => (new Evaluate($h->debugger, $mcp))->call(['expression' => '1']));
        $step = self::error(static fn() => (new Control($h->debugger, $mcp))->call(['command' => 'run']));
        $set = self::error(static fn() => (new BreakpointsTool($h->debugger, $mcp))->call(['action' => 'set', 'file' => '/a.php', 'line' => 1]));
        $list = (new BreakpointsTool($h->debugger, $mcp))->call([]);

        Assert::string($eval)->contains('TRAP_XDEBUG_ALLOW_EVAL');
        Assert::string($step)->contains('TRAP_XDEBUG_ALLOW_CONTROL');
        Assert::string($set)->contains('TRAP_XDEBUG_ALLOW_CONTROL');
        Assert::same($list, ['breakpoints' => []]);
    }

    public function testListsSessions(): void
    {
        [$h, $mcp] = self::paused();

        $result = (new Sessions($h->debugger, $mcp))->call(['live_only' => true]);

        Assert::count($result['sessions'], 1);
        Assert::same($result['mode'], 'standalone');
    }

    /**
     * @return array{Harness, McpStatus}
     */
    private static function paused(Config $config = new Config()): array
    {
        $config->pauseOnFirstLine = true;
        $config->mode = Config::MODE_STANDALONE;
        $h = new Harness(new FakeEngine([
            'step_into' => static fn(array $c): string => FakeEngine::breakAt('step_into', $c['tid'], 'file:///app/a.php', 3),
            'step_over' => static fn(array $c): string => FakeEngine::breakAt('step_over', $c['tid'], 'file:///app/a.php', 4),
            'stack_get' => static fn(array $c): string => FakeEngine::response(
                'stack_get',
                $c['tid'],
                '',
                '<stack where="run" level="0" type="file" filename="file:///app/a.php" lineno="3"></stack>'
                . '<stack where="{main}" level="1" type="file" filename="file:///app/index.php" lineno="9"></stack>',
            ),
            'source' => static function (array $c): string {
                \preg_match('/-b (\d+) -e (\d+)/', $c['args'], $m);
                $lines = \array_slice(["\$a = 1;\n", "\$b = 2;\n", "\$c = 3;\n", "  \$d = 4;\n"], (int) $m[1] - 1, (int) $m[2] - (int) $m[1] + 1);
                return FakeEngine::response('source', $c['tid'], ' encoding="base64"', \base64_encode(\implode('', $lines)));
            },
            'breakpoint_set' => static fn(array $c): string => FakeEngine::response('breakpoint_set', $c['tid'], ' id="9001" resolved="resolved"'),
            'breakpoint_list' => static fn(array $c): string => FakeEngine::response(
                'breakpoint_list',
                $c['tid'],
                '',
                '<breakpoint type="line" resolved="resolved" filename="file:///app/a.php" lineno="4" hit_count="1" id="9001"></breakpoint>',
            ),
            'context_get' => static fn(array $c): string => FakeEngine::response('context_get', $c['tid'], '', '<property name="$a" type="int"><![CDATA[1]]></property>'),
        ]), $config);
        $h->connect();
        $h->pump(30);

        return [$h, new McpStatus()];
    }

    private static function error(\Closure $call): string
    {
        try {
            $call();
        } catch (\Throwable $e) {
            return $e->getMessage();
        }

        return '';
    }
}
