<?php

declare(strict_types=1);

namespace Buggregator\Trap\Support\Caster;

/**
 * @internal
 */
final class TraceFile
{
    /**
     * @param array{
     *     function: non-empty-string,
     *     line?: int,
     *     file?: string,
     *     class?: class-string,
     *     type?: string,
     *     args?: list<mixed>
     * } $line The stack trace line.
     */
    public function __construct(
        public readonly array $line,
    ) {}

    public function __toString(): string
    {
        return isset($this->line['file'], $this->line['line'])
            ? \basename($this->line['file']) . ':' . $this->line['line']
            : '<internal>';
    }
}
