<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Profiler\Http;

use Buggregator\Trap\Module\Profiler\BuildLock;
use Buggregator\Trap\Module\Profiler\Frame;
use Buggregator\Trap\Module\Profiler\Http\XHProfTrap;
use Buggregator\Trap\Module\Profiler\XHProf\ProfileBuilder;
use Buggregator\Trap\Server\Logger;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Covers(XHProfTrap::class)]
#[Test]
final class XHProfTrapTest
{
    private const STUB = __DIR__ . '/../../../../Stub/xhprof/app.json';

    public function testStoresProfileFromPayload(): void
    {
        $begin = new \DateTimeImmutable('2025-05-18 12:00:00');
        $request = (new ServerRequest('POST', '/api/profiler/store', body: \file_get_contents(self::STUB)))
            ->withAttribute('begin_at', $begin);

        [$response, $frames] = self::handle($request);

        Assert::same($response->getStatusCode(), 200);
        Assert::count($frames, 1);
        Assert::instanceOf($frames[0], Frame::class);
        $profile = $frames[0]->payload->getProfile();
        Assert::same($profile->date, $begin);
        Assert::same($profile->tags, ['env' => 'test']);
        Assert::same($profile->metadata, ['app_name' => 'app', 'hostname' => 'host']);
        Assert::same(\count($profile->calls), 2223);
        Assert::same($profile->peaks->wt, 45310);
    }

    public function testRejectsInvalidJson(): void
    {
        [$response, $frames] = self::handle(new ServerRequest('POST', '/api/profiler/store', body: '{"profile":'));

        Assert::same($response->getStatusCode(), 400);
        Assert::same($frames, []);
    }

    public function testRejectsPayloadWithoutProfile(): void
    {
        [$response, $frames] = self::handle(new ServerRequest('POST', '/api/profiler/store', body: '{"tags":{}}'));

        Assert::same($response->getStatusCode(), 400);
        Assert::same($frames, []);
    }

    public function testPassesOtherRequestsThrough(): void
    {
        [$response, $frames] = self::handle(new ServerRequest('GET', '/api/profiler/store'));

        Assert::same($response->getStatusCode(), 404);
        Assert::same($frames, []);
    }

    public function testRejectsProfileWhenTooManyBuildsWait(): void
    {
        $lock = new BuildLock(maxWaiting: 0);
        $building = new \Fiber(static fn() => $lock->run(static fn() => \Fiber::suspend()));
        $building->start();
        $request = new ServerRequest('POST', '/api/profiler/store', body: \file_get_contents(self::STUB));

        [$response, $frames] = self::handle($request, $lock);

        Assert::same($response->getStatusCode(), 503);
        Assert::same($frames, []);
    }

    public function testBuildsProfileBeforeAccepting(): void
    {
        $lock = new BuildLock();
        $request = new ServerRequest('POST', '/api/profiler/store', body: \file_get_contents(self::STUB));

        [, $frames] = self::handle($request, $lock);
        $building = new \Fiber(static fn() => $lock->run(static fn(): string => 'free'));
        $building->start();

        // Built under the lock, the profile is ready: showing it builds nothing
        Assert::same($building->getReturn(), 'free');
        Assert::same(\count($frames[0]->payload->getProfile()->calls), 2223);
    }

    /**
     * @return array{ResponseInterface, list<mixed>} The response and the frames the middleware suspended with.
     */
    private static function handle(ServerRequest $request, BuildLock $lock = new BuildLock()): array
    {
        $middleware = new XHProfTrap(new ProfileBuilder(), new Logger(), $lock);
        $fiber = new \Fiber(static fn(): ResponseInterface => $middleware->handle($request, static fn() => new Response(404)));

        // A null lets the other fibers run during a long build
        $frames = [];
        $frame = $fiber->start();
        while (!$fiber->isTerminated()) {
            $frame === null or $frames[] = $frame;
            $frame = $fiber->resume();
        }

        return [$fiber->getReturn(), $frames];
    }
}
