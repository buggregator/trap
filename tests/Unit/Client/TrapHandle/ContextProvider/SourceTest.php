<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Client\TrapHandle\ContextProvider;

use Buggregator\Trap\Client\TrapHandle\ContextProvider\Source;
use Buggregator\Trap\Client\TrapHandle\StaticState;
use PHPUnit\Framework\TestCase;

use function PHPUnit\Framework\assertSame;

final class SourceTest extends TestCase
{
    /**
     * @test
     */
    public function here(): void
    {
        $this->staticState();

        self::assertSame(\basename(__FILE__), $this->getContext()['name']);
        self::assertSame(__FILE__, $this->getContext()['file']);
        self::assertSame(__LINE__ - 4, $this->getContext()['line']);
    }

    /**
     * @test
     */
    public function on_destruct(): void
    {
        $this->staticState();

        new class() {
            public function __destruct()
            {
                assertSame(\basename(__FILE__), (new Source())->getContext()['name']);
                assertSame(__FILE__, (new Source())->getContext()['file']);
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
