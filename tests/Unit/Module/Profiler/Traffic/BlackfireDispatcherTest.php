<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Profiler\Traffic;

use Buggregator\Trap\Module\Profiler\Blackfire\ProfileBuilder;
use Buggregator\Trap\Module\Profiler\BuildLock;
use Buggregator\Trap\Module\Profiler\Frame;
use Buggregator\Trap\Module\Profiler\Traffic\BlackfireDispatcher;
use Buggregator\Trap\Server\Logger;
use Buggregator\Trap\Server\Proto\Frame as ProtoFrame;
use Buggregator\Trap\Test\Mock\StreamClientMock;
use Buggregator\Trap\Tests\Unit\FiberTrait;
use Buggregator\Trap\Tests\Unit\Module\Profiler\Blackfire\ProfileBuilderTest;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Covers(BlackfireDispatcher::class)]
final class BlackfireDispatcherTest
{
    use FiberTrait;

    private const QUERY = "Blackfire-Query: expires=4102444800&signature=trap&profile_title=Checkout\n"
        . "Blackfire-Probe: php-8.3.35, config\n\n";

    public static function detectProvider(): iterable
    {
        yield 'empty' => ['', 0.0, null];
        yield 'prefix of the query' => ['Blackfire-Qu', 0.0, null];
        yield 'query' => ['Blackfire-Query: expires=1', 0.0, true];
        yield 'monitoring' => ["file-format: BlackfireApm\n", 0.0, true];
        yield 'prefix of the monitoring' => ['file-fo', 0.0, null];
        yield 'prefix after the silence' => ['Blackfire-Qu', 2.0, false];
        yield 'empty after the silence' => ['', 2.0, false];
        yield 'http' => ["GET / HTTP/1.1\r\n", 0.0, false];
        yield 'other header' => ['Blackfire-Probe: php', 0.0, false];
    }

    public static function droppedProvider(): iterable
    {
        yield 'closed before the empty line' => ["Blackfire-Query: expires=1&signature=x\n", null];
        yield 'malformed profile' => [self::QUERY, "file-format: BlackfireProbe\ncost-dimensions: wt\n\nmain()//1\n"];
        yield 'no profile' => [self::QUERY, null];
    }

    #[Test]
    #[DataProvider('detectProvider')]
    public function detects(string $data, float $silence, ?bool $expected): void
    {
        Assert::same(self::dispatcher()->detect($data, $silence), $expected);
    }

    #[Test]
    public function answersTheQueryAndTakesTheProfile(): void
    {
        $answers = [];
        $stream = StreamClientMock::createFromGenerator((static function () use (&$answers): \Generator {
            $answers[] = yield self::QUERY;
            foreach (\str_split(ProfileBuilderTest::CLI, 100) as $chunk) {
                $answers[] = yield $chunk;
            }
        })());

        $frames = $this->dispatch($stream);

        Assert::same(\implode('', $answers), "Blackfire-Response: continue=true\n\n");
        Assert::count($frames, 1);
        $frame = $frames[0];
        Assert::instanceOf($frame, Frame::class);
        Assert::same($frame->payload->type, Frame\Type::Blackfire);
        $profile = $frame->payload->getProfile();
        Assert::same($profile->tags, ['name' => 'Checkout', 'command' => 'bin/console cache:clear']);
        Assert::same($profile->peaks->wt, 1000);
    }

    #[Test]
    public function closesTheMonitoringUnanswered(): void
    {
        $answers = [];
        $stream = StreamClientMock::createFromGenerator((static function () use (&$answers): \Generator {
            $answers[] = yield "file-format: BlackfireApm\ncapabilities: trace, profile\n\n";
        })());

        $frames = $this->dispatch($stream);

        Assert::same($frames, []);
        Assert::same(\implode('', $answers), '');
        Assert::true($stream->isDisconnected());
    }

    #[Test]
    #[DataProvider('droppedProvider')]
    public function dropsWhatIsNotAProfile(string $query, ?string $profile): void
    {
        $stream = StreamClientMock::createFromGenerator((static function () use ($query, $profile): \Generator {
            yield $query;
            $profile === null or yield $profile;
        })());

        Assert::same($this->dispatch($stream), []);
    }

    #[Test]
    public function dropsAQueryOverTheLimitUnanswered(): void
    {
        $answers = [];
        $sent = 0;
        $stream = StreamClientMock::createFromGenerator((static function () use (&$answers, &$sent): \Generator {
            $answers[] = yield 'Blackfire-Query: ';
            // No line end: the limit holds before a line is whole
            while (\is_string($answer = yield \str_repeat('a', 1024))) {
                $answers[] = $answer;
                if (++$sent > 1000) {
                    return;
                }
            }
        })());

        $frames = $this->dispatch($stream);

        Assert::same($frames, []);
        Assert::same(\implode('', $answers), '');
        Assert::int($sent)->lessThan(70);
        Assert::true($stream->isDisconnected());
    }

    #[Test]
    public function dropsAProfileOverTheLimit(): void
    {
        $stream = StreamClientMock::createFromGenerator((static function (): \Generator {
            yield self::QUERY;
            yield "file-format: BlackfireProbe\ncost-dimensions: wt\n\n";
            $chunk = \str_repeat("main()==>f//1 1\n", 65536);
            for ($size = 0; $size <= BlackfireDispatcher::MAX_SIZE; $size += \strlen($chunk)) {
                yield $chunk;
            }
        })());

        Assert::same($this->dispatch($stream), []);
    }

    #[Test]
    public function dropsAProfileWhileTheBuildsQueueIsFull(): void
    {
        $lock = new BuildLock(maxWaiting: 0);
        $holder = new \Fiber(static fn() => $lock->run(static fn() => \Fiber::suspend()));
        $holder->start();
        $stream = StreamClientMock::createFromGenerator((static function (): \Generator {
            yield self::QUERY;
            yield ProfileBuilderTest::CLI;
        })());
        $dispatcher = new BlackfireDispatcher(new ProfileBuilder(), new Logger(), $lock);

        $frames = $this->runInFiber(static fn(): array => [...$dispatcher->dispatch($stream)]);

        Assert::same($frames, []);
        $holder->resume();
        Assert::true($holder->isTerminated());
    }

    private static function dispatcher(): BlackfireDispatcher
    {
        return new BlackfireDispatcher(new ProfileBuilder(), new Logger(), new BuildLock());
    }

    /**
     * @return list<ProtoFrame>
     */
    private function dispatch(StreamClientMock $stream): array
    {
        return $this->runInFiber(static fn(): array => [...self::dispatcher()->dispatch($stream)]);
    }
}
