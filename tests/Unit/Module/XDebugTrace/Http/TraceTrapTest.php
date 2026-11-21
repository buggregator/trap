<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\XDebugTrace\Http;

use Buggregator\Trap\Module\XDebugTrace\Config\Traces;
use Buggregator\Trap\Module\XDebugTrace\Frame;
use Buggregator\Trap\Module\XDebugTrace\Frontend\Mapper;
use Buggregator\Trap\Module\XDebugTrace\Http\TraceTrap;
use Buggregator\Trap\Module\XDebugTrace\TraceStorage;
use Buggregator\Trap\Server\Event\EventStore;
use Buggregator\Trap\Server\Logger;
use Buggregator\Trap\Tests\Unit\Module\XDebugTrace\Trace\TraceReaderTest;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Covers(TraceTrap::class)]
#[Covers(Frame::class)]
#[Covers(Mapper::class)]
#[Test]
final class TraceTrapTest
{
    public function testStoresTraceSentWithHeader(): void
    {
        $request = (new ServerRequest('POST', '/', [
            'X-Buggregator-Event' => 'xdebug-trace',
            'X-Buggregator-Trace-Name' => ' users ',
            'X-Buggregator-Trace-Url' => 'GET /users',
            'X-Buggregator-Project' => 'shop',
        ], \file_get_contents(TraceReaderTest::MAIN)))
            ->withAttribute('begin_at', new \DateTimeImmutable('2026-11-08 10:00:00'));

        [$response, $frames, $storage] = self::handle($request);

        Assert::same($response->getStatusCode(), 200);
        Assert::count($frames, 1);
        $frame = $frames[0];
        Assert::instanceOf($frame, Frame::class);
        Assert::same($frame->name, 'users');
        Assert::same($frame->url, 'GET /users');
        Assert::same($frame->project, 'shop');
        Assert::true($frame->compressed);
        Assert::same($frame->file, $frame->uuid . '.xt.gz');
        Assert::same($frame->summary['calls'], 39);
        Assert::notSame($storage->path($frame->uuid), null);
    }

    public function testTakesUserOfBasicAuthorization(): void
    {
        $request = new ServerRequest('POST', '/', [
            'Authorization' => 'Basic ' . \base64_encode('xdebug-trace:'),
        ], \file_get_contents(TraceReaderTest::MID));

        [$response, $frames] = self::handle($request);

        Assert::same($response->getStatusCode(), 200);
        Assert::count($frames, 1);
    }

    public function testPassesOtherRequestsThrough(): void
    {
        [$get] = self::handle(new ServerRequest('GET', '/', ['X-Buggregator-Event' => 'xdebug-trace']));
        [$other] = self::handle(new ServerRequest('POST', '/', ['X-Buggregator-Event' => 'ray'], '{}'));

        Assert::same($get->getStatusCode(), 404);
        Assert::same($other->getStatusCode(), 404);
    }

    public function testRejectsOtherContent(): void
    {
        $request = new ServerRequest('POST', '/', ['X-Buggregator-Event' => 'xdebug-trace'], 'hello');

        [$response, $frames, , $log] = self::handle($request);

        Assert::same($response->getStatusCode(), 400);
        Assert::same($frames, []);
        Assert::string($log)->contains('xdebug.trace_format=1');
    }

    public function testRejectsTraceOverUploadLimit(): void
    {
        $config = new Traces();
        $config->maxUpload = '1K';
        $request = new ServerRequest('POST', '/', ['X-Buggregator-Event' => 'xdebug-trace'], \file_get_contents(TraceReaderTest::MAIN));

        [$response, $frames] = self::handle($request, $config);

        Assert::same($response->getStatusCode(), 413);
        Assert::same($frames, []);
    }

    public function testMapsFrameToEventOfStoredForm(): void
    {
        $request = new ServerRequest('POST', '/', ['X-Buggregator-Event' => 'xdebug-trace'], \file_get_contents(TraceReaderTest::MID));
        [, $frames] = self::handle($request);

        $restored = Frame::fromString((string) $frames[0], $frames[0]->time);
        $event = (new Mapper())->map($restored);

        Assert::same($event->uuid, $frames[0]->uuid);
        Assert::same($event->type, 'xdebug-trace');
        Assert::array($event->payload)->hasKeys('file', 'name', 'url', 'size', 'compressed', 'summary');
        Assert::same($event->payload['summary'], $frames[0]->summary);
    }

    /**
     * @return array{ResponseInterface, list<Frame>, TraceStorage, string}
     */
    private static function handle(ServerRequest $request, Traces $config = new Traces()): array
    {
        $output = new BufferedOutput();
        $storage = new TraceStorage($config, new EventStore());
        $middleware = new TraceTrap($storage, new Logger($output));
        $fiber = new \Fiber(static fn(): ResponseInterface => $middleware->handle($request, static fn() => new Response(404)));

        $frames = [];
        $frame = $fiber->start();
        while (!$fiber->isTerminated()) {
            $frame === null or $frames[] = $frame;
            $frame = $fiber->resume();
        }

        return [$fiber->getReturn(), $frames, $storage, $output->fetch()];
    }
}
