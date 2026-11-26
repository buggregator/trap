<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Profiler\Http;

use Buggregator\Trap\Module\Profiler\BuildLock;
use Buggregator\Trap\Module\Profiler\Frame;
use Buggregator\Trap\Module\Profiler\Http\StacksTrap;
use Buggregator\Trap\Module\Profiler\Stacks\ProfileBuilder;
use Buggregator\Trap\Server\Logger;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Test;

#[Covers(StacksTrap::class)]
#[Test]
final class StacksTrapTest
{
    private const TRACE = "0 foo /app/a.php:1\n1 <main> /app/a.php:2\n\n0 <main> /app/a.php:3\n\n";

    public function testStoresPhpspyTraceWithQueryMetadata(): void
    {
        $begin = new \DateTimeImmutable('2026-11-26 12:00:00');
        $request = (new ServerRequest('POST', '/api/profiler/phpspy', body: self::TRACE))
            ->withQueryParams([
                'app_name' => 'app',
                'hostname' => 'host',
                'name' => 'checkout',
                'period' => '0.01',
                'tags' => ['env' => 'test', 'bad' => ['x']],
            ])
            ->withAttribute('begin_at', $begin);

        [$response, $frames] = self::handle($request);

        Assert::same($response->getStatusCode(), 200);
        Assert::count($frames, 1);
        Assert::same($frames[0]->payload->type, Frame\Type::Phpspy);
        $profile = $frames[0]->payload->getProfile();
        Assert::same($profile->date, $begin);
        Assert::same($profile->metadata, [
            'app_name' => 'app',
            'hostname' => 'host',
            'profile_type' => 'sampled',
            'clock' => 'wall',
            'period' => 0.01,
        ]);
        Assert::same($profile->tags, ['env' => 'test', 'name' => 'checkout']);
        Assert::same([$profile->peaks->wt, $profile->peaks->ct], [20000, 2]);
    }

    public function testStoresGzippedCollapsedStacks(): void
    {
        $request = new ServerRequest('POST', '/api/profiler/collapsed', body: \gzencode("main;foo 3\nmain 1\n"));

        [$response, $frames] = self::handle($request);

        Assert::same($response->getStatusCode(), 200);
        Assert::same($frames[0]->payload->type, Frame\Type::Collapsed);
        Assert::same($frames[0]->payload->getProfile()->peaks->ct, 4);
    }

    public function testTakesClockOfCollapsedStacks(): void
    {
        $request = (new ServerRequest('POST', '/api/profiler/collapsed', body: "main;foo 3\n"))
            ->withQueryParams(['clock' => 'cpu', 'period' => '0.001']);

        [$response, $frames] = self::handle($request);

        Assert::same($response->getStatusCode(), 200);
        $profile = $frames[0]->payload->getProfile();
        Assert::same($profile->metadata['clock'], 'cpu');
        Assert::same([$profile->peaks->wt, $profile->peaks->cpu], [3000, 3000]);
    }

    public function testRejectsUnknownClock(): void
    {
        $request = (new ServerRequest('POST', '/api/profiler/collapsed', body: "main;foo 3\n"))
            ->withQueryParams(['clock' => 'gpu']);

        [$response, , $log] = self::handle($request);

        Assert::same($response->getStatusCode(), 400);
        Assert::string($log)->contains('the `clock` parameter is neither `wall` nor `cpu`.');
    }

    public function testPassesOtherRequestsThrough(): void
    {
        foreach ([
            new ServerRequest('POST', '/api/other', body: self::TRACE),
            new ServerRequest('GET', '/api/profiler/phpspy'),
        ] as $request) {
            [$response, $frames, $log] = self::handle($request);

            Assert::same($response->getStatusCode(), 404);
            Assert::same($frames, []);
            Assert::same($log, '');
        }
    }

    public function testRejectsOversizedBody(): void
    {
        $request = new ServerRequest('POST', '/api/profiler/phpspy', body: self::TRACE);

        [$response, $frames, $log] = self::handle($request, new ProfileBuilder(maxSize: 16));

        Assert::same($response->getStatusCode(), 413);
        Assert::same($frames, []);
        Assert::string($log)->contains('Phpspy: profile rejected with 413: the body is too large.');
    }

    public function testRejectsInvalidTraceWithReason(): void
    {
        $request = new ServerRequest('POST', '/api/profiler/collapsed', body: "main;foo\n");

        [$response, $frames, $log] = self::handle($request);

        Assert::same($response->getStatusCode(), 400);
        Assert::string((string) $response->getBody())->contains('Line 1 is not a collapsed stack');
        Assert::same($frames, []);
        Assert::string($log)->contains('Collapsed: profile rejected with 400: line 1');
    }

    #[DataSet(['abc'])]
    #[DataSet(['1abc'])]
    #[DataSet(['0'])]
    #[DataSet(['INF'])]
    #[DataSet([['0.01']])]
    public function testRejectsInvalidPeriod(mixed $period): void
    {
        $request = (new ServerRequest('POST', '/api/profiler/phpspy', body: self::TRACE))
            ->withQueryParams(['period' => $period]);

        [$response, $frames, $log] = self::handle($request);

        Assert::same($response->getStatusCode(), 400);
        Assert::same($frames, []);
        Assert::string($log)->contains('Phpspy: profile rejected with 400: the `period` parameter');
    }

    public function testRejectsProfileWhenTooManyBuildsWait(): void
    {
        $lock = new BuildLock(maxWaiting: 0);
        $building = new \Fiber(static fn() => $lock->run(static fn() => \Fiber::suspend()));
        $building->start();

        [$response, $frames, $log] = self::handle(
            new ServerRequest('POST', '/api/profiler/phpspy', body: self::TRACE),
            lock: $lock,
        );

        Assert::same($response->getStatusCode(), 503);
        Assert::same($frames, []);
        Assert::string($log)->contains('Phpspy: profile rejected with 503: 0 profile builds are waiting already.');
    }

    public function testIgnoresClockOfPhpspyTrace(): void
    {
        $request = (new ServerRequest('POST', '/api/profiler/phpspy', body: self::TRACE))
            ->withQueryParams(['clock' => 'gpu', 'period' => '0.001']);

        [$response, $frames] = self::handle($request);

        Assert::same($response->getStatusCode(), 200);
        Assert::same($frames[0]->payload->getProfile()->metadata['clock'], 'wall');
    }

    public function testDropsEmptyAndNonStringQueryValues(): void
    {
        $request = (new ServerRequest('POST', '/api/profiler/collapsed', body: "main;foo 3\n"))
            ->withQueryParams([
                'app_name' => '',
                'hostname' => ['host'],
                'name' => '',
                'tags' => ['' => 'x', 'empty' => '', 0 => 'indexed', 'env' => 'dev'],
            ]);

        [$response, $frames] = self::handle($request);

        Assert::same($response->getStatusCode(), 200);
        $profile = $frames[0]->payload->getProfile();
        Assert::same($profile->metadata, ['profile_type' => 'sampled']);
        Assert::same($profile->tags, ['env' => 'dev']);
    }

    public function testIgnoresTagsThatAreNotList(): void
    {
        $request = (new ServerRequest('POST', '/api/profiler/collapsed', body: "main;foo 3\n"))
            ->withQueryParams(['tags' => 'env']);

        [$response, $frames] = self::handle($request);

        Assert::same($response->getStatusCode(), 200);
        Assert::same($frames[0]->payload->getProfile()->tags, []);
    }

    public function testDatesProfileByReceiptWithoutBeginTime(): void
    {
        $request = (new ServerRequest('POST', '/api/profiler/phpspy', body: self::TRACE))
            ->withAttribute('begin_at', '2026-11-26 12:00:00');
        $before = new \DateTimeImmutable();

        [, $frames] = self::handle($request);

        $date = $frames[0]->payload->getProfile()->date;
        Assert::true($date >= $before && $date <= new \DateTimeImmutable());
        Assert::same($frames[0]->time, $date);
    }

    public function testTakesPathUnderPrefix(): void
    {
        [$response, $frames] = self::handle(new ServerRequest('POST', '/trap/api/profiler/phpspy', body: self::TRACE));

        Assert::same($response->getStatusCode(), 200);
        Assert::same($frames[0]->payload->type, Frame\Type::Phpspy);
    }

    public function testPassesLongerPathThrough(): void
    {
        [$response, $frames] = self::handle(new ServerRequest('POST', '/api/profiler/phpspy/more', body: self::TRACE));

        Assert::same($response->getStatusCode(), 404);
        Assert::same($frames, []);
    }

    public function testAcceptsBodyOfMaxSize(): void
    {
        $request = new ServerRequest('POST', '/api/profiler/phpspy', body: self::TRACE);

        [$response] = self::handle($request, new ProfileBuilder(maxSize: \strlen(self::TRACE)));

        Assert::same($response->getStatusCode(), 200);
    }

    public function testRejectsBodyOfUnknownSize(): void
    {
        $body = \Mockery::mock(StreamInterface::class);
        $body->allows('getSize')->andReturns(null);
        $request = new ServerRequest('POST', '/api/profiler/collapsed', body: $body);

        [$response, $frames, $log] = self::handle($request);

        Assert::same($response->getStatusCode(), 413);
        Assert::same($frames, []);
        Assert::string($log)->contains('Collapsed: profile rejected with 413: the body is too large.');
    }

    public function testRejectsBodyFailingToReadAndLogsError(): void
    {
        $body = \Mockery::mock(StreamInterface::class);
        $body->allows('getSize')->andReturns(\strlen(self::TRACE));
        $body->allows('__toString')->andThrows(new \RuntimeException('The connection is reset.'));
        $request = new ServerRequest('POST', '/api/profiler/phpspy', body: $body);

        [$response, $frames, $log] = self::handle($request);

        Assert::same($response->getStatusCode(), 400);
        Assert::same((string) $response->getBody(), 'The connection is reset.');
        Assert::same($frames, []);
        Assert::string($log)->contains('The connection is reset.')->notContains('profile rejected');
    }

    public function testWarnsOfCompactedProfile(): void
    {
        $content = '';
        for ($i = 0; $i < 30; ++$i) {
            $content .= "main;f$i 1\n";
        }
        $request = new ServerRequest('POST', '/api/profiler/collapsed', body: $content);

        [$response, $frames, $log] = self::handle($request, new ProfileBuilder(maxNodes: 5));

        Assert::same($response->getStatusCode(), 200);
        Assert::count($frames, 1);
        Assert::string($log)->contains('Collapsed: the profile is compacted to 5 call tree nodes: ')
            ->contains('nodes of cold subtrees summed in `(other)`');
    }

    public function testDoesNotWarnOfProfileWithinNodeLimit(): void
    {
        [$response, , $log] = self::handle(new ServerRequest('POST', '/api/profiler/phpspy', body: self::TRACE));

        Assert::same($response->getStatusCode(), 200);
        Assert::same($log, '');
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
        $middleware = new StacksTrap($builder, new Logger($output), $lock);
        $fiber = new \Fiber(
            static fn(): ResponseInterface => $middleware->handle($request, static fn() => new Response(404)),
        );

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
