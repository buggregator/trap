<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Profiler\Http;

use Buggregator\Trap\Module\Profiler\BuildLock;
use Buggregator\Trap\Module\Profiler\Frame;
use Buggregator\Trap\Module\Profiler\Http\PprofTrap;
use Buggregator\Trap\Module\Profiler\Http\SpeedscopeTrap;
use Buggregator\Trap\Module\Profiler\Http\XDebugTrap;
use Buggregator\Trap\Module\Profiler\Http\XHProfTrap;
use Buggregator\Trap\Module\Profiler\Pprof\ProfileBuilder as PprofProfileBuilder;
use Buggregator\Trap\Module\Profiler\Speedscope\ProfileBuilder as SpeedscopeProfileBuilder;
use Buggregator\Trap\Module\Profiler\XDebug\ProfileBuilder as XDebugProfileBuilder;
use Buggregator\Trap\Module\Profiler\XHProf\ProfileBuilder as XHProfProfileBuilder;
use Buggregator\Trap\Server\Logger;
use Buggregator\Trap\Server\Traffic\Dispatcher\Http;
use Buggregator\Trap\Test\Mock\StreamClientMock;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * The profiles `trap test` sends, through the HTTP parser and the profiler middlewares.
 */
#[Covers(XHProfTrap::class)]
#[Covers(XDebugTrap::class)]
#[Covers(SpeedscopeTrap::class)]
#[Covers(PprofTrap::class)]
#[Test]
final class ProfilerPayloadsTest
{
    private const PAYLOADS = __DIR__ . '/../../../../../resources/payloads';

    public function testXhprofPayload(): void
    {
        $frame = self::dispatch('yii-xhprof.http');

        Assert::same($frame->payload->type, Frame\Type::XHProf);
        $profile = $frame->payload->getProfile();
        Assert::same($profile->metadata['app_name'], 'my-app');
        Assert::same($profile->tags, ['foo' => 'bar', 'test' => 'tags']);
        Assert::same(\count($profile->calls), 1119);
        Assert::same($profile->peaks->wt, 266329);
    }

    public function testXdebugPayload(): void
    {
        $frame = self::dispatch('testo-xdebug.http');

        Assert::same($frame->payload->type, Frame\Type::XDebug);
        $profile = $frame->payload->getProfile();
        Assert::same($profile->metadata, ['app_name' => 'testo', 'hostname' => 'trap']);
        Assert::same($profile->tags, ['tool' => 'testo']);
        Assert::same(\count($profile->tree()), 15195);
        Assert::same(\count($profile->calls), 1908);
    }

    public function testSpeedscopePayloadSurvivesFrameRoundTrip(): void
    {
        $body = \json_encode([
            '$schema' => SpeedscopeProfileBuilder::SCHEMA,
            'exporter' => 'Excimer',
            'shared' => ['frames' => [['name' => 'Foo::bar', 'file' => '/app/src/Foo.php'], ['name' => 'main']]],
            'profiles' => [[
                'type' => 'sampled',
                'unit' => 'nanoseconds',
                'samples' => [[1, 0], [1]],
                'weights' => [1000000, 2000000],
            ]],
        ]);
        $raw = "POST /api/profiler/speedscope?app_name=app&hostname=host&tags%5Benv%5D=dev HTTP/1.1\r\n"
            . "Host: 127.0.0.1:8000\r\nConnection: close\r\nContent-Type: application/json\r\n"
            . 'Content-Length: ' . \strlen($body) . "\r\n\r\n" . $body;

        $frame = self::dispatchRaw($raw);
        $restored = Frame::fromString((string) $frame, $frame->time);

        Assert::same($restored->payload->type, Frame\Type::Speedscope);
        $profile = $restored->payload->getProfile();
        Assert::same($profile->metadata['app_name'], 'app');
        Assert::same($profile->metadata['hostname'], 'host');
        Assert::same($profile->tags, ['env' => 'dev']);
        Assert::same($profile->peaks->jsonSerialize(), ['ct' => 2, 'wt' => 3000, 'cpu' => 0, 'mu' => 0, 'pmu' => 0]);
        Assert::same($profile->uuid, $frame->payload->getProfile()->uuid);
    }

    public function testGzippedPprofPayloadSurvivesFrameRoundTrip(): void
    {
        $body = (string) \file_get_contents(__DIR__ . '/../../../../Stub/pprof/upstream/fib.pb.gz');
        $raw = "POST /api/profiler/pprof?app_name=app&tags%5Benv%5D=dev HTTP/1.1\r\n"
            . "Host: 127.0.0.1:8000\r\nConnection: close\r\nContent-Type: application/octet-stream\r\n"
            . 'Content-Length: ' . \strlen($body) . "\r\n\r\n" . $body;

        $frame = self::dispatchRaw($raw);
        $restored = Frame::fromString((string) $frame, $frame->time);

        Assert::same($restored->payload->type, Frame\Type::Pprof);
        $profile = $restored->payload->getProfile();
        Assert::same($profile->metadata['app_name'], 'app');
        Assert::same($profile->tags, ['env' => 'dev']);
        Assert::same($profile->peaks->jsonSerialize(), ['ct' => 2974, 'wt' => 29_740_000, 'cpu' => 29_740_000, 'mu' => 0, 'pmu' => 0]);
    }

    private static function dispatch(string $payload): Frame
    {
        return self::dispatchRaw((string) \file_get_contents(self::PAYLOADS . '/' . $payload));
    }

    private static function dispatchRaw(string $raw): Frame
    {
        $stream = StreamClientMock::createFromGenerator((static function () use ($raw): \Generator {
            foreach (\str_split($raw, 4096) as $chunk) {
                yield $chunk;
            }
            while ((yield '') !== null) {
                // Takes the responses
            }
        })());
        $dispatcher = new Http([
            new XHProfTrap(new XHProfProfileBuilder(), new Logger(), $lock = new BuildLock()),
            new XDebugTrap(new XDebugProfileBuilder(), new Logger(), $lock),
            new SpeedscopeTrap(new SpeedscopeProfileBuilder(), new Logger(), $lock),
            new PprofTrap(new PprofProfileBuilder(), new Logger(), $lock),
        ], []);

        $fiber = new \Fiber(static fn(): array => \iterator_to_array($dispatcher->dispatch($stream), false));
        $fiber->start();
        while (!$fiber->isTerminated()) {
            $fiber->resume();
        }

        $frames = $fiber->getReturn();
        Assert::count($frames, 1);
        Assert::instanceOf($frames[0], Frame::class);

        return $frames[0];
    }
}
