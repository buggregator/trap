<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Profiler\Http;

use Buggregator\Trap\Module\Profiler\BuildLock;
use Buggregator\Trap\Module\Profiler\Frame;
use Buggregator\Trap\Module\Profiler\Http\XDebugTrap;
use Buggregator\Trap\Module\Profiler\XDebug\ProfileBuilder;
use Buggregator\Trap\Server\Logger;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Covers(XDebugTrap::class)]
#[Test]
final class XDebugTrapTest
{
    private const STUB = __DIR__ . '/../../../../Stub/xdebug/shared.cachegrind';

    public function testStoresProfileWithQueryMetadata(): void
    {
        $begin = new \DateTimeImmutable('2025-05-18 12:00:00');
        $request = (new ServerRequest('POST', '/api/profiler/xdebug', body: \file_get_contents(self::STUB)))
            ->withQueryParams(['app_name' => 'app', 'hostname' => 'host', 'tags' => ['env' => 'test', 'bad' => ['x']]])
            ->withAttribute('begin_at', $begin);

        [$response, $frames] = self::handle($request);

        Assert::same($response->getStatusCode(), 200);
        Assert::count($frames, 1);
        Assert::instanceOf($frames[0], Frame::class);
        Assert::same($frames[0]->payload->type, Frame\Type::XDebug);
        $profile = $frames[0]->payload->getProfile();
        Assert::same($profile->date, $begin);
        Assert::same($profile->metadata, ['app_name' => 'app', 'hostname' => 'host']);
        Assert::same($profile->tags, ['env' => 'test']);
        Assert::same(\count($profile->tree()), 15);
    }

    public function testAcceptsGzippedBody(): void
    {
        $request = new ServerRequest('POST', '/api/profiler/xdebug', body: \gzencode(\file_get_contents(self::STUB)));

        [$response, $frames] = self::handle($request);

        Assert::same($response->getStatusCode(), 200);
        Assert::count($frames, 1);
    }

    public function testRejectsGzippedBodyExpandingOverLimit(): void
    {
        $content = \file_get_contents(self::STUB);
        $request = new ServerRequest('POST', '/api/profiler/xdebug', body: \gzencode($content));

        [$response, $frames] = self::handle($request, new ProfileBuilder(maxSize: \strlen($content) - 1));

        Assert::same($response->getStatusCode(), 400);
        Assert::same($frames, []);
    }

    public function testRejectsOtherContent(): void
    {
        [$response, $frames] = self::handle(new ServerRequest('POST', '/api/profiler/xdebug', body: '{"profile":{}}'));

        Assert::same($response->getStatusCode(), 400);
        Assert::same($frames, []);
    }

    public function testPassesOtherRequestsThrough(): void
    {
        [$response, $frames] = self::handle(new ServerRequest('POST', '/api/profiler/store', body: '{}'));

        Assert::same($response->getStatusCode(), 404);
        Assert::same($frames, []);
    }

    public function testWarnsThatProfileIsCompacted(): void
    {
        $request = new ServerRequest('POST', '/api/profiler/xdebug', body: \file_get_contents(self::STUB));

        [$response, $frames, $log] = self::handle($request, new ProfileBuilder(maxNodes: 3));

        Assert::same($response->getStatusCode(), 200);
        Assert::true(\count($frames[0]->payload->getProfile()->tree()) < 15);
        Assert::string($log)->matchesRegex(
            '/XDebug: the profile is compacted to 3 call tree nodes: \d+ nodes merged into calls of the same function/',
        );
    }

    public function testRejectsProfileWhenTooManyBuildsWait(): void
    {
        $lock = new BuildLock(maxWaiting: 0);
        $building = new \Fiber(static fn() => $lock->run(static fn() => \Fiber::suspend()));
        $building->start();
        $request = new ServerRequest('POST', '/api/profiler/xdebug', body: \file_get_contents(self::STUB));

        [$response, $frames, $log] = self::handle($request, lock: $lock);

        Assert::same($response->getStatusCode(), 503);
        Assert::same($frames, []);
        Assert::string($log)->contains('XDebug: profile rejected with 503: 0 profile builds are waiting already.');
    }

    /**
     * @return array{ResponseInterface, list<mixed>, string} The response, the frames the middleware suspended with
     *         and the log.
     */
    private static function handle(
        ServerRequest $request,
        ProfileBuilder $builder = new ProfileBuilder(),
        BuildLock $lock = new BuildLock(),
    ): array {
        $output = new BufferedOutput();
        $middleware = new XDebugTrap($builder, new Logger($output), $lock);
        $fiber = new \Fiber(static fn(): ResponseInterface => $middleware->handle($request, static fn() => new Response(404)));

        // A null lets the other fibers run during a long build
        $frames = [];
        $frame = $fiber->start();
        while (!$fiber->isTerminated()) {
            $frame === null or $frames[] = $frame;
            $frame = $fiber->resume();
        }

        return [$fiber->getReturn(), $frames, $output->fetch()];
    }
}
