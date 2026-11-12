<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Profiler\Http;

use Buggregator\Trap\Module\Profiler\BuildLock;
use Buggregator\Trap\Module\Profiler\Frame;
use Buggregator\Trap\Module\Profiler\Http\PprofTrap;
use Buggregator\Trap\Module\Profiler\Pprof\ProfileBuilder;
use Buggregator\Trap\Server\Logger;
use Buggregator\Trap\Tests\Unit\Module\Profiler\Pprof\Stub\PprofProfile;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Covers(PprofTrap::class)]
#[Test]
final class PprofTrapTest
{
    public function testStoresProfileWithQueryMetadata(): void
    {
        $begin = new \DateTimeImmutable('2025-05-18 12:00:00');
        $request = self::request('/api/profiler/pprof', \gzencode(self::profile()))
            ->withQueryParams([
                'app_name' => 'app',
                'hostname' => 'host',
                'name' => 'checkout',
                'tags' => ['env' => 'test', 'bad' => ['x']],
            ])
            ->withAttribute('begin_at', $begin);

        [$response, $frames] = self::handle($request);

        Assert::same($response->getStatusCode(), 200);
        Assert::count($frames, 1);
        Assert::instanceOf($frames[0], Frame::class);
        Assert::same($frames[0]->payload->type, Frame\Type::Pprof);
        $profile = $frames[0]->payload->getProfile();
        Assert::same($profile->date, $begin);
        Assert::same($profile->metadata, [
            'app_name' => 'app',
            'hostname' => 'host',
            'profile_type' => 'sampled',
            'sample_types' => 'samples/count, cpu/nanoseconds',
            'clock' => 'cpu',
            'period' => 10_000_000,
            'period_type' => 'cpu/nanoseconds',
        ]);
        Assert::same($profile->tags, ['env' => 'test', 'name' => 'checkout']);
        Assert::same([$profile->peaks->wt, $profile->peaks->cpu, $profile->peaks->ct], [30_000, 30_000, 3]);
    }

    public function testStoresRawProtobuf(): void
    {
        [$response, $frames] = self::handle(self::request('/api/profiler/pprof', self::profile()));

        Assert::same($response->getStatusCode(), 200);
        Assert::same($frames[0]->payload->getProfile()->peaks->ct, 3);
    }

    public function testPassesOtherRequestsThrough(): void
    {
        $requests = [
            self::request('/api/other', \gzencode(self::profile())),
            new ServerRequest('GET', '/api/profiler/pprof'),
        ];

        foreach ($requests as $request) {
            [$response, $frames, $log] = self::handle($request);

            Assert::same($response->getStatusCode(), 404);
            Assert::same($frames, []);
            Assert::same($log, '');
        }
    }

    public function testLeavesErrorsOfNextMiddlewaresAlone(): never
    {
        Expect::exception(\RuntimeException::class)->withMessage('next failed');

        self::handle(self::request('/api/other', 'x'), next: static fn() => throw new \RuntimeException('next failed'));
    }

    public function testRejectsOversizedBody(): void
    {
        [$response, $frames, $log] = self::handle(self::request('/api/profiler/pprof', self::profile()), new ProfileBuilder(maxSize: 16));

        Assert::same($response->getStatusCode(), 413);
        Assert::same($frames, []);
        Assert::string($log)->contains('Pprof: profile rejected with 413: the body is too large.');
    }

    public function testRejectsMalformedProfile(): void
    {
        [$response, $frames, $log] = self::handle(self::request('/api/profiler/pprof', "\x0B"));

        Assert::same($response->getStatusCode(), 400);
        Assert::same((string) $response->getBody(), 'Unsupported wire type 3 of field 1 at byte 0.');
        Assert::same($frames, []);
        Assert::string($log)->contains('Pprof: profile rejected with 400: unsupported wire type 3');
    }

    public function testRejectsInvalidGzip(): void
    {
        [$response, , $log] = self::handle(self::request('/api/profiler/pprof', "\x1F\x8B\x08\x00junk"));

        Assert::same($response->getStatusCode(), 400);
        Assert::string($log)->contains('Pprof: profile rejected with 400: invalid gzip data.');
    }

    public function testWarnsThatProfileIsCompacted(): void
    {
        [$response, $frames, $log] = self::handle(
            self::request('/api/profiler/pprof', self::profile()),
            new ProfileBuilder(maxNodes: 1),
        );

        Assert::same($response->getStatusCode(), 200);
        Assert::same($frames[0]->payload->getProfile()->metadata['compacted']['limit'], 1);
        Assert::string($log)->matchesRegex('/Pprof: the profile is compacted to 1 call tree nodes: /');
    }

    public function testRejectsProfileWhenTooManyBuildsWait(): void
    {
        $lock = new BuildLock(maxWaiting: 0);
        $building = new \Fiber(static fn() => $lock->run(static fn() => \Fiber::suspend()));
        $building->start();

        [$response, $frames, $log] = self::handle(self::request('/api/profiler/pprof', self::profile()), lock: $lock);

        Assert::same($response->getStatusCode(), 503);
        Assert::same($frames, []);
        Assert::string($log)->contains('Pprof: profile rejected with 503: 0 profile builds are waiting already.');
    }

    public function testReleasesLockBeforePassingFrameOut(): void
    {
        $lock = new BuildLock(maxWaiting: 0);
        $middleware = new PprofTrap(new ProfileBuilder(), new Logger(), $lock);
        $request = self::request('/api/profiler/pprof', self::profile());
        $fiber = new \Fiber(static fn(): ResponseInterface => $middleware->handle($request, static fn() => new Response(404)));

        $frame = $fiber->start();
        while ($frame === null && !$fiber->isTerminated()) {
            $frame = $fiber->resume();
        }

        Assert::instanceOf($frame, Frame::class);
        Assert::same($lock->run(static fn(): string => 'free'), 'free');
    }

    public function testAnswersUnexpectedErrorWith400(): void
    {
        $file = \tempnam(\sys_get_temp_dir(), 'trap');
        $resource = \fopen($file, 'w');
        \fwrite($resource, self::profile());
        // A write-only body: reading it fails with a `RuntimeException`
        $request = new ServerRequest('POST', '/api/profiler/pprof', [], Stream::create($resource));

        try {
            [$response, $frames, $log] = self::handle($request);
        } finally {
            \fclose($resource);
            \unlink($file);
        }

        Assert::same($response->getStatusCode(), 400);
        Assert::string((string) $response->getBody())->contains('stream');
        Assert::same($frames, []);
        Assert::string($log)->contains((string) $response->getBody());
    }

    private static function profile(): string
    {
        $pprof = new PprofProfile();
        $pprof->period = 10_000_000;
        $pprof->periodType = ['cpu', 'nanoseconds'];
        [$main, $foo] = [$pprof->frame('main.main', '/app/main.go'), $pprof->frame('main.foo', '/app/main.go')];
        $pprof->sample([$foo, $main], [1, 10_000_000])->sample([$main], [2, 20_000_000]);

        return $pprof->encode();
    }

    private static function request(string $path, string $body): ServerRequest
    {
        return new ServerRequest('POST', $path, ['Content-Type' => 'application/octet-stream'], $body);
    }

    /**
     * @return array{ResponseInterface, list<mixed>, string} The response, the frames the middleware suspended with
     *         and the log.
     */
    private static function handle(
        ServerRequest $request,
        ProfileBuilder $builder = new ProfileBuilder(),
        ?\Closure $next = null,
        BuildLock $lock = new BuildLock(),
    ): array {
        $output = new BufferedOutput();
        $middleware = new PprofTrap($builder, new Logger($output), $lock);
        $next ??= static fn(): ResponseInterface => new Response(404);
        $fiber = new \Fiber(static fn(): ResponseInterface => $middleware->handle($request, $next));

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
