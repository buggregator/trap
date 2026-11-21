<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\XDebug\Frontend;

use Buggregator\Trap\Module\Frontend\Http\Router as HttpRouter;
use Buggregator\Trap\Module\XDebug\Config\Debugger as Config;
use Buggregator\Trap\Module\XDebug\Frontend\ApiController;
use Buggregator\Trap\Server\Config\TcpPorts;
use Buggregator\Trap\Server\Handler\Router\Method;
use Buggregator\Trap\Server\Handler\Router\Router;
use Buggregator\Trap\Tests\Unit\Module\XDebug\Stub\FakeEngine;
use Buggregator\Trap\Tests\Unit\Module\XDebug\Stub\Harness;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Covers(ApiController::class)]
#[Test]
final class ApiControllerTest
{
    public function testShowsConfigWithDebuggerOff(): void
    {
        $h = new Harness();
        $router = self::router($h, enabled: false);

        $status = self::call($h, $router, Method::Get, 'api/xdebug');
        $sessions = self::call($h, $router, Method::Get, 'api/xdebug/sessions');

        Assert::same($status, [
            'enabled' => false,
            'addr' => '127.0.0.1:9003',
            'ide_addr' => '127.0.0.1:9004',
            'allow_eval' => false,
            'allow_control' => true,
            'mode' => 'auto',
            'pause_on_first_line' => false,
            'idle_timeout_seconds' => 600,
        ]);
        Assert::same($sessions, [404, ['error' => 'The Xdebug debugger is off: start Trap with the --xdebug option.', 'code' => 'not_found']]);
    }

    public function testReadsPausedSession(): void
    {
        $h = self::paused();
        $router = self::router($h);

        $list = self::call($h, $router, Method::Get, 'api/xdebug/sessions');
        $stack = self::call($h, $router, Method::Get, 'api/xdebug/sessions/1/stack');
        $badFrame = self::call($h, $router, Method::Get, 'api/xdebug/sessions/1/contexts?frame=x');
        $missing = self::call($h, $router, Method::Get, 'api/xdebug/sessions/9/stack');
        $live = self::call($h, $router, Method::Delete, 'api/xdebug/sessions/1');

        Assert::same($list[0]['state'], 'break');
        Assert::same($stack[0], ['level' => 0, 'where' => '{main}', 'file' => 'file:///app/a.php', 'line' => 3, 'type' => 'file', 'vendor' => false]);
        Assert::same($badFrame[0], 400);
        Assert::same($missing[1]['code'], 'not_found');
        Assert::same($live, [409, ['error' => 'The session is live: only a closed session can be dismissed.', 'code' => 'session_live']]);
    }

    public function testControlsAndRefusesEval(): void
    {
        $h = self::paused();
        $router = self::router($h);

        $eval = self::call($h, $router, Method::Post, 'api/xdebug/sessions/1/eval', ['expression' => '1']);
        $run = self::call($h, $router, Method::Post, 'api/xdebug/sessions/1/step-over', []);
        $again = self::call($h, $router, Method::Post, 'api/xdebug/sessions/1/run', []);

        Assert::same($eval[1]['code'], 'eval_disabled');
        Assert::same($run['state'], 'running');
        Assert::same($again, [409, ['error' => $again[1]['error'], 'code' => 'not_paused', 'state' => 'running']]);
        Assert::contains($h->engine->commands, 'step_over -i 9');
    }

    public function testManagesBreakpointsAndSettings(): void
    {
        $h = new Harness();
        $router = self::router($h);

        $created = self::call($h, $router, Method::Post, 'api/xdebug/breakpoints', ['type' => 'line', 'file' => '/app/a.php', 'line' => 4]);
        $condition = self::call($h, $router, Method::Post, 'api/xdebug/breakpoints', ['file' => '/app/a.php', 'line' => 5, 'condition' => '$a']);
        $patched = self::call($h, $router, Method::Patch, 'api/xdebug/breakpoints/1', ['enabled' => false]);
        $deleted = self::call($h, $router, Method::Delete, 'api/xdebug/breakpoints/1');
        $settings = self::call($h, $router, Method::Put, 'api/xdebug/settings', ['mode' => 'standalone']);
        $badMode = self::call($h, $router, Method::Put, 'api/xdebug/settings', ['mode' => 'ide']);

        Assert::same($created[0], 201);
        Assert::same($created[1]['file'], 'file:///app/a.php');
        Assert::same($condition[1]['code'], 'eval_disabled');
        Assert::false($patched['enabled']);
        Assert::same($deleted, [204, null]);
        Assert::same($settings, ['mode' => 'standalone', 'pause_on_first_line' => false, 'idle_timeout_seconds' => 600]);
        Assert::same($badMode[0], 400);
        Assert::contains($h->eventNames(), 'breakpoints.changed');
    }

    public function testRefusesOtherOriginsAndRpc(): void
    {
        $h = self::paused();
        $router = self::router($h);

        $crossSite = self::call($h, $router, Method::Get, 'api/xdebug/sessions/1/stack', headers: ['Sec-Fetch-Site' => 'cross-site']);
        $textPlain = self::call($h, $router, Method::Post, 'api/xdebug/sessions/1/step-over', headers: ['Content-Type' => 'text/plain']);
        $rpc = self::call($h, $router, Method::Post, 'api/xdebug/breakpoints', ['file' => '/a.php', 'line' => 1], http: false);
        $sameSite = self::call($h, $router, Method::Get, 'api/xdebug', headers: ['Sec-Fetch-Site' => 'same-origin']);

        Assert::same($crossSite[0], 403);
        Assert::same($crossSite[1]['code'], 'cross_site');
        Assert::same($textPlain[0], 415);
        Assert::same($rpc[1]['code'], 'http_only');
        Assert::true($sameSite['enabled']);
        Assert::same($h->session()->state(), 'break');
        Assert::same($h->debugger->breakpoints->all(), []);
    }

    public function testReadsSourceOfKnownFilesOnly(): void
    {
        $h = self::paused();
        $router = self::router($h);

        $host = self::call($h, $router, Method::Get, 'api/xdebug/sessions/1/source?file=file:///C:/Windows/win.ini');

        Assert::same($host[0], 404);
        Assert::false($h->debugger->knowsFile('file:///C:/Windows/win.ini'));
        Assert::true($h->debugger->knowsFile('file:///app/a.php'));
    }

    private static function paused(): Harness
    {
        $config = new Config();
        $config->pauseOnFirstLine = true;
        $config->mode = Config::MODE_STANDALONE;
        $h = new Harness(new FakeEngine([
            'step_into' => static fn(array $c): string => FakeEngine::breakAt('step_into', $c['tid'], 'file:///app/a.php', 3),
            'step_over' => static fn(): string => '',
            'stack_get' => static fn(array $c): string => FakeEngine::response('stack_get', $c['tid'], '', '<stack where="{main}" level="0" type="file" filename="file:///app/a.php" lineno="3"></stack>'),
        ]), $config);
        $h->connect();
        $h->pump(30);

        return $h;
    }

    private static function router(Harness $h, bool $enabled = true): Router
    {
        $controller = new ApiController($h->debugger, new TcpPorts());
        if (!$enabled) {
            (new \ReflectionProperty($h->debugger, 'enabled'))->setValue($h->debugger, false);
        }

        return Router::compose($controller);
    }

    /**
     * @return mixed The answer, or `[status, body]` of a response.
     */
    private static function call(
        Harness $h,
        Router $router,
        Method $method,
        string $uri,
        array $body = [],
        array $headers = ['Content-Type' => 'application/json'],
        bool $http = true,
    ): mixed {
        $handler = $router->match($method, $uri);
        \assert($handler !== null, "No route for $uri");
        $http and $body[HttpRouter::REQUEST] = new ServerRequest($method->value, "/$uri", $headers);
        $result = $h->call(static fn(): mixed => $handler(...$body));

        return $result instanceof ResponseInterface
            ? [$result->getStatusCode(), \json_decode((string) $result->getBody(), true)]
            : $result;
    }
}
