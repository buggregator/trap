<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Profiler\Speedscope;

/**
 * Functions of the frames of a speedscope document: frames of the same name are one function.
 *
 * @internal
 */
final class Frames
{
    /** @var list<non-empty-string> Function names by IDs */
    public array $names = [];

    /** @var list<int<0, max>> Function IDs by frame indexes */
    private array $ids = [];

    /** @var array<non-empty-string, int<0, max>> */
    private array $byName = [];

    /**
     * @param non-empty-string $name
     */
    public function add(string $name): void
    {
        if (!isset($this->byName[$name])) {
            $this->byName[$name] = \count($this->names);
            $this->names[] = $name;
        }
        $this->ids[] = $this->byName[$name];
    }

    /**
     * @return int<0, max> Function ID of the frame.
     *
     * @throws \InvalidArgumentException When there is no such frame.
     */
    public function id(mixed $index): int
    {
        return \is_int($index) && isset($this->ids[$index])
            ? $this->ids[$index]
            : throw new \InvalidArgumentException('Unknown frame index: ' . ProfileBuilder::describe($index) . '.');
    }

    /**
     * @return int<0, max> Number of functions.
     */
    public function count(): int
    {
        return \count($this->names);
    }
}
