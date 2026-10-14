<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Client\TrapHandle\ContextProvider;

use Buggregator\Trap\Client\TrapHandle\ContextProvider\Source;
use Buggregator\Trap\Client\TrapHandle\StaticState;
use Testo\Assert;
use Testo\Test;

#[Test]
final class SourceTest
{
    public function testHere(): void
    {
        $this->staticState();

        Assert::same($this->getContext()['name'], \basename(__FILE__));
        Assert::same($this->getContext()['file'], __FILE__);
        Assert::same($this->getContext()['line'], __LINE__ - 4);
    }

    public function testOnDestruct(): void
    {
        $this->staticState();

        new class {
            public function __destruct()
            {
                Assert::same((new Source())->getContext()['name'], \basename(__FILE__));
                Assert::same((new Source())->getContext()['file'], __FILE__);
            }
        };
    }

    /**
     * @return array{
     *     name: string,
     *     file: string,
     *     line: int,
     *     file_excerpt: bool
     * }
     */
    private function getContext(): array
    {
        return (new Source())->getContext();
    }

    /**
     * Create a new StaticState instance.
     */
    private function staticState(): StaticState
    {
        return StaticState::new();
    }
}
