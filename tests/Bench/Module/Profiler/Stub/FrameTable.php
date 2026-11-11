<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Bench\Module\Profiler\Stub;

/**
 * Speedscope `shared.frames`, deduplicated by name and file as Excimer does.
 */
final class FrameTable
{
    /** @var list<array{name: string, file: string}> */
    public array $frames = [];

    /** @var array<string, int> */
    private array $index = [];

    public function add(string $name, string $file): int
    {
        return $this->index["$name\0$file"] ??= \array_push($this->frames, ['name' => $name, 'file' => $file]) - 1;
    }
}
