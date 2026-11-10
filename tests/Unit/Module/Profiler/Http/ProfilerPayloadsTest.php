<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Profiler\Http;

use Buggregator\Trap\Module\Profiler\Frame;
use Buggregator\Trap\Module\Profiler\Http\XDebugTrap;
use Buggregator\Trap\Module\Profiler\Http\XHProfTrap;
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

    private static function dispatch(string $payload): Frame
    {
        $raw = (string) \file_get_contents(self::PAYLOADS . '/' . $payload);
        $stream = StreamClientMock::createFromGenerator((static function () use ($raw): \Generator {
            foreach (\str_split($raw, 4096) as $chunk) {
                yield $chunk;
            }
            while ((yield '') !== null) {
                // Takes the responses
            }
        })());
        $dispatcher = new Http([
            new XHProfTrap(new XHProfProfileBuilder(), new Logger()),
            new XDebugTrap(new XDebugProfileBuilder(), new Logger()),
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
