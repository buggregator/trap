<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\XDebugTrace\Trace;

/**
 * The N calls with the most inclusive time: a min-heap, whose top is the call to drop next.
 *
 * @psalm-import-type Call from Summary
 * @extends \SplHeap<Call>
 *
 * @internal
 * @psalm-suppress ImmutableDependency A heap changes itself, as its base does
 */
final class SlowestCalls extends \SplHeap
{
    /**
     * @param positive-int $size
     */
    public function __construct(
        private readonly int $size,
    ) {}

    /**
     * @param Call $call
     */
    public function push(array $call): void
    {
        if ($this->count() < $this->size) {
            $this->insert($call);
            return;
        }

        if ($this->top()['incl'] < $call['incl']) {
            $this->extract();
            $this->insert($call);
        }
    }

    /**
     * Empties the heap.
     *
     * @return list<Call> The slowest first; of equal times, the earlier call first.
     */
    public function sorted(): array
    {
        $calls = [];
        while (!$this->isEmpty()) {
            $calls[] = $this->extract();
        }

        return \array_reverse($calls);
    }

    /**
     * Positive when $value1 goes above $value2: the faster call, or of equal times the later one.
     *
     * @param Call $value1
     * @param Call $value2
     */
    #[\Override]
    protected function compare(mixed $value1, mixed $value2): int
    {
        return [$value2['incl'], $value1['nr']] <=> [$value1['incl'], $value2['nr']];
    }
}
