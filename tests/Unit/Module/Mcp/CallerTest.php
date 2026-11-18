<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Mcp;

use Buggregator\Trap\Module\Mcp\Caller;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(Caller::class)]
final class CallerTest
{
    public function testOutsideFiberRunsTheCodeWithTheClientConnected(): void
    {
        $caller = new Caller();

        $result = $caller->connected(
            static fn(): bool => true,
            static fn(): array => ['disconnected' => $caller->isDisconnected()],
        );

        Assert::same($result, ['disconnected' => false]);
    }

    public function testFiberSeesItsConnectionUntilTheCallEnds(): void
    {
        $caller = new Caller();
        $fiber = new \Fiber(static fn(): array => [
            $caller->connected(static fn(): bool => true, $caller->isDisconnected(...)),
            $caller->isDisconnected(),
        ]);

        $fiber->start();

        Assert::same($fiber->getReturn(), [true, false]);
    }
}
