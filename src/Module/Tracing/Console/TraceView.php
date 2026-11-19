<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Tracing\Console;

use Buggregator\Trap\Module\Tracing\Frame\Trace\Span;

/**
 * What one trace of a frame shows: the spans picked for printing and how each of them is printed.
 *
 * @internal
 * @psalm-internal Buggregator\Trap\Module\Tracing\Console
 */
final class TraceView
{
    /**
     * @param array<non-empty-string, bool> $shown Span ID => whether the span is an ancestor shown as context only.
     * @param array<non-empty-string, int> $collapsed Span ID => number of its earlier descendants left out.
     * @param array<non-empty-string, int> $hiddenAncestors Context span ID => number of known ancestors above it.
     * @param array<non-empty-string, list<Span>> $children Span ID => its shown children, in the order of printing.
     */
    public function __construct(
        public readonly KnownSpans $known,
        public readonly array $shown,
        public readonly array $collapsed,
        public readonly array $hiddenAncestors,
        public readonly array $children,
    ) {}
}
