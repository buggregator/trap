<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Server\Handler;

use Buggregator\Trap\Server\Handler\Pipeline;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(Pipeline::class)]
final class PipelineTest
{
    public function callsTheLastHandlerWithoutMiddlewares(): void
    {
        $pipeline = Pipeline::build([], 'handle', static fn(string $a, string $b): string => $a . $b);

        Assert::same($pipeline('x', 'y'), 'xy');
    }

    public function runsMiddlewaresInOrderAroundTheLastHandler(): void
    {
        $log = new \ArrayObject();
        $middlewares = (static function () use ($log): \Generator {
            yield 'first' => self::middleware('a', $log);
            yield 'second' => self::middleware('b', $log);
        })();
        $pipeline = Pipeline::build($middlewares, 'handle', static function (string $value) use ($log): string {
            $log[] = 'last';
            return $value . '!';
        });

        $result = $pipeline('v');

        Assert::same($result, '[a[bv!b]a]');
        Assert::same($log->getArrayCopy(), ['a', 'b', 'last']);
    }

    public function middlewareMayAnswerWithoutTheNextOnes(): void
    {
        $called = false;
        $stop = new class {
            public function handle(string $value, Pipeline $next): string
            {
                return 'stopped';
            }
        };
        $pipeline = Pipeline::build([$stop], 'handle', static function () use (&$called): string {
            $called = true;
            return 'last';
        });

        Assert::same($pipeline('v'), 'stopped');
        Assert::false($called);
    }

    public function canBeInvokedAgain(): void
    {
        $pipeline = Pipeline::build(
            [self::middleware('a', new \ArrayObject())],
            'handle',
            static fn(string $value): string => $value,
        );

        Assert::same($pipeline('1'), '[a1a]');
        Assert::same($pipeline('2'), '[a2a]');
    }

    private static function middleware(string $name, \ArrayObject $log): object
    {
        return new class($name, $log) {
            public function __construct(
                private readonly string $name,
                private readonly \ArrayObject $log,
            ) {}

            public function handle(string $value, Pipeline $next): string
            {
                $this->log[] = $this->name;
                return "[{$this->name}" . $next($value) . "{$this->name}]";
            }
        };
    }
}
