<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Profiler\Stacks;

/**
 * Functions of the stack frames, by name.
 *
 * @internal
 */
final class Functions
{
    /** @var list<non-empty-string> Names by IDs */
    public array $names = [];

    /** @var array<non-empty-string, int<0, max>> */
    private array $byName = [];

    /**
     * @param non-empty-string $name
     *
     * @return int<0, max> ID of the function, added when there is none.
     */
    public function id(string $name): int
    {
        if (!isset($this->byName[$name])) {
            $this->byName[$name] = \count($this->names);
            $this->names[] = $name;
        }

        return $this->byName[$name];
    }

    /**
     * @return int<0, max>
     */
    public function count(): int
    {
        return \count($this->names);
    }
}
