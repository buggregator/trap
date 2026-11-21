<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Frontend;

use Buggregator\Trap\Module\Frontend\BadRouteArgument;
use Buggregator\Trap\Server\Handler\Router\Exception\InvalidRouteArgument;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Covers(BadRouteArgument::class)]
#[Test]
final class BadRouteArgumentTest
{
    public function testParameterOfWrongTypeIsTold(): void
    {
        Assert::true(BadRouteArgument::isOf(self::call(['p' => []]), self::method()));
    }

    public function testTypeErrorInRouteCodeIsNot(): void
    {
        Assert::false(BadRouteArgument::isOf(self::call(['p' => 'x']), self::method()));
        Assert::false(BadRouteArgument::isOf(new \RuntimeException(), self::method()));
    }

    public function testMissingParameterIsTold(): void
    {
        Assert::true(BadRouteArgument::isOf(self::call([], self::required()), self::required()));
        Assert::true(BadRouteArgument::isOf(new InvalidRouteArgument(), null));
    }

    private static function call(array $args, ?\ReflectionMethod $method = null): \Throwable
    {
        try {
            ($method ?? self::method())->invokeArgs(new self(), $args);
        } catch (\Throwable $e) {
            return $e;
        }

        return Assert::fail('No error');
    }

    private static function required(): \ReflectionMethod
    {
        return new \ReflectionMethod(self::class, 'needs');
    }

    private static function method(): \ReflectionMethod
    {
        return new \ReflectionMethod(self::class, 'target');
    }

    private function needs(string $p): void {}

    private function target(?string $p = null): void
    {
        /** @psalm-suppress InvalidArgument, UnusedFunctionCall */
        \strlen(\unserialize('a:0:{}'));
    }
}
