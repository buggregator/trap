<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Profiler\Http;

use Buggregator\Trap\Module\Profiler\BuildLock;
use Buggregator\Trap\Module\Profiler\Frame;
use Buggregator\Trap\Module\Profiler\Http\SpeedscopeTrap;
use Buggregator\Trap\Module\Profiler\Speedscope\ProfileBuilder;
use Buggregator\Trap\Server\Logger;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Expect;
use Testo\Test;

#[Covers(SpeedscopeTrap::class)]
#[Test]
final class SpeedscopeTrapTest
{
    public function testStoresProfileWithQueryMetadata(): void
    {
        $begin = new \DateTimeImmutable('2025-05-18 12:00:00');
        $request = self::request('/api/profiler/speedscope', self::document())
            ->withQueryParams([
                'app_name' => 'app',
                'hostname' => 'host',
                'name' => 'checkout',
                'clock' => 'cpu',
                'tags' => ['env' => 'test', 'bad' => ['x']],
            ])
            ->withAttribute('begin_at', $begin);

        [$response, $frames] = self::handle($request);

        Assert::same($response->getStatusCode(), 200);
        Assert::count($frames, 1);
        Assert::instanceOf($frames[0], Frame::class);
        Assert::same($frames[0]->payload->type, Frame\Type::Speedscope);
        $profile = $frames[0]->payload->getProfile();
        Assert::same($profile->date, $begin);
        Assert::same($profile->metadata, [
            'app_name' => 'app',
            'hostname' => 'host',
            'exporter' => 'Excimer',
            'clock' => 'cpu',
            'profile_type' => 'sampled',
        ]);
        Assert::same($profile->tags, ['env' => 'test', 'name' => 'checkout']);
        Assert::same([$profile->peaks->wt, $profile->peaks->cpu, $profile->peaks->ct], [3000, 3000, 2]);
    }

    public function testDetectsSchemaOnOtherPath(): void
    {
        // Escaped slashes, as `json_encode()` writes them by default
        $request = new ServerRequest('POST', '/', ['Content-Type' => 'application/json'], \json_encode(self::document()));

        [$response, $frames] = self::handle($request);

        Assert::same($response->getStatusCode(), 200);
        Assert::count($frames, 1);
        Assert::same($frames[0]->payload->getProfile()->metadata['clock'], 'wall');
    }

    public function testPassesOtherJsonThrough(): void
    {
        $document = ['$schema' => 'https://example.com/speedscope.app/file-format-schema.json'] + self::document();
        $requests = [
            self::request('/api/other', ['message' => 'hello']),
            self::request('/api/other', $document),
            new ServerRequest('POST', '/', ['Content-Type' => 'text/plain'], \json_encode(self::document())),
            new ServerRequest('GET', '/api/profiler/speedscope'),
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
        $request = self::request('/api/other', ['message' => 'hello']);

        Expect::exception(\RuntimeException::class)->withMessage('next failed');

        self::handle($request, next: static fn() => throw new \RuntimeException('next failed'));
    }

    public function testRejectsOversizedBody(): void
    {
        $request = self::request('/api/profiler/speedscope', self::document());

        [$response, $frames, $log] = self::handle($request, new ProfileBuilder(maxSize: 16));

        Assert::same($response->getStatusCode(), 413);
        Assert::same($frames, []);
        Assert::string($log)->contains('Speedscope: profile rejected with 413: the body is too large.');
    }

    public function testRejectsInvalidDocument(): void
    {
        [$response, $frames, $log] = self::handle(self::request('/api/profiler/speedscope', ['profiles' => []]));

        Assert::same($response->getStatusCode(), 400);
        Assert::same($frames, []);
        Assert::string($log)->contains('Speedscope: profile rejected with 400: no `shared.frames`');
    }

    public function testRejectsUnknownClock(): void
    {
        $request = self::request('/api/profiler/speedscope', self::document())->withQueryParams(['clock' => 'gpu']);

        [$response, $frames, $log] = self::handle($request);

        Assert::same($response->getStatusCode(), 400);
        Assert::same($frames, []);
        Assert::string($log)->contains('the `clock` parameter');
    }

    public function testCountsTimerEventsOfSampleByPeriod(): void
    {
        $request = self::request('/api/profiler/speedscope', self::document())->withQueryParams(['period' => '0.001']);

        [$response, $frames] = self::handle($request);

        Assert::same($response->getStatusCode(), 200);
        $profile = $frames[0]->payload->getProfile();
        // 1 ms and 2 ms at a 1 ms period: 1 and 2 timer events
        Assert::same($profile->calls->roots()[0]->cost->ct, 3);
        Assert::same($profile->calls->callers('Foo::bar')[0]->cost->ct, 1);
    }

    #[DataSet(['abc'])]
    #[DataSet(['0'])]
    #[DataSet(['-0.001'])]
    #[DataSet(['INF'])]
    #[DataSet([['0.001']])]
    public function testRejectsInvalidPeriod(mixed $period): void
    {
        $request = self::request('/api/profiler/speedscope', self::document())->withQueryParams(['period' => $period]);

        [$response, $frames, $log] = self::handle($request);

        Assert::same($response->getStatusCode(), 400);
        Assert::same($frames, []);
        Assert::string($log)->contains('Speedscope: profile rejected with 400: the `period` parameter');
    }

    public function testWarnsThatProfileIsCompacted(): void
    {
        $request = self::request('/api/profiler/speedscope', self::document());

        [$response, $frames, $log] = self::handle($request, new ProfileBuilder(maxNodes: 1));

        Assert::same($response->getStatusCode(), 200);
        Assert::same($frames[0]->payload->getProfile()->metadata['compacted']['limit'], 1);
        Assert::string($log)->matchesRegex(
            '/Speedscope: the profile is compacted to 1 call tree nodes: .*\d+ nodes of cold subtrees summed in `\(other\)`/',
        );
    }

    public function testRejectsProfileWhenTooManyBuildsWait(): void
    {
        $lock = new BuildLock(maxWaiting: 0);
        $building = new \Fiber(static fn() => $lock->run(static fn() => \Fiber::suspend()));
        $building->start();

        [$response, $frames, $log] = self::handle(self::request('/api/profiler/speedscope', self::document()), lock: $lock);

        Assert::same($response->getStatusCode(), 503);
        Assert::same($frames, []);
        Assert::string($log)->contains('Speedscope: profile rejected with 503: 0 profile builds are waiting already.');
    }

    public function testReleasesLockBeforePassingFrameOut(): void
    {
        $lock = new BuildLock(maxWaiting: 0);
        $middleware = new SpeedscopeTrap(new ProfileBuilder(), new Logger(), $lock);
        $request = self::request('/api/profiler/speedscope', self::document());
        $fiber = new \Fiber(static fn(): ResponseInterface => $middleware->handle($request, static fn() => new Response(404)));

        $frame = $fiber->start();
        while ($frame === null && !$fiber->isTerminated()) {
            $frame = $fiber->resume();
        }

        Assert::instanceOf($frame, Frame::class);
        Assert::same($lock->run(static fn(): string => 'free'), 'free');
        $other = new \Fiber(static fn() => $lock->run(static fn(): string => 'free'));
        $other->start();
        Assert::same($other->getReturn(), 'free');
    }

    public function testDoesNotQueueJsonThatOnlyMentionsSchema(): void
    {
        $lock = new BuildLock(maxWaiting: 0);
        $building = new \Fiber(static fn() => $lock->run(static fn() => \Fiber::suspend()));
        $building->start();
        $body = ['message' => 'see ' . ProfileBuilder::SCHEMA] + self::document();

        [$response, $frames, $log] = self::handle(self::request('/api/other', $body), lock: $lock);

        Assert::same($response->getStatusCode(), 404);
        Assert::same($frames, []);
        Assert::same($log, '');
    }

    private static function document(): array
    {
        return [
            '$schema' => ProfileBuilder::SCHEMA,
            'exporter' => 'Excimer',
            'shared' => ['frames' => [['name' => 'Foo::bar', 'file' => '/app/src/Foo.php'], ['name' => 'main']]],
            'profiles' => [[
                'type' => 'sampled',
                'name' => '',
                'unit' => 'nanoseconds',
                'startValue' => 0,
                'endValue' => 3000000,
                'samples' => [[1, 0], [1]],
                'weights' => [1000000, 2000000],
            ]],
        ];
    }

    private static function request(string $path, array $body): ServerRequest
    {
        return new ServerRequest(
            'POST',
            $path,
            ['Content-Type' => 'application/json'],
            \json_encode($body, \JSON_UNESCAPED_SLASHES),
        );
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
        $middleware = new SpeedscopeTrap($builder, new Logger($output), $lock);
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
