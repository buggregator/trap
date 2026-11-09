<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Profiler\Http;

use Buggregator\Trap\Logger;
use Buggregator\Trap\Module\Profiler\Frame;
use Buggregator\Trap\Module\Profiler\Http\XDebugTrap;
use Buggregator\Trap\Module\Profiler\XDebug\ProfileBuilder;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
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

    /**
     * @return array{ResponseInterface, list<mixed>} The response and the frames the middleware suspended with.
     */
    private static function handle(ServerRequest $request, ProfileBuilder $builder = new ProfileBuilder()): array
    {
        $middleware = new XDebugTrap($builder, new Logger());
        $fiber = new \Fiber(static fn(): ResponseInterface => $middleware->handle($request, static fn() => new Response(404)));

        $frames = [];
        $frame = $fiber->start();
        while (!$fiber->isTerminated()) {
            $frames[] = $frame;
            $frame = $fiber->resume();
        }

        return [$fiber->getReturn(), $frames];
    }
}
