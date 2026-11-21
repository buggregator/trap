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
            'stack_get' => static fn(array $c): string => FakeEngine::response('stack_get', $c['tid'], '', '<stack where="{main}" level="0" type="file" filename="file:///app/a.php" lineno="3"></stack>'),
            'source' => static fn(array $c): string => FakeEngine::response('source', $c['tid'], ' encoding="base64"', \base64_encode("\$a = 1;\n\$b = 2;\n\$c = 3;\n")),
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
