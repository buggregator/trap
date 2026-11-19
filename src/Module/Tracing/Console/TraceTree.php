<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Tracing\Console;

use Buggregator\Trap\Module\Console\Support\Common;
use Buggregator\Trap\Module\Tracing\Frame\Trace\Span;
use Buggregator\Trap\Module\Tracing\Frame\Trace\StatusCode;
use Buggregator\Trap\Module\Tracing\SpanStore;
use Buggregator\Trap\Support\Json;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Prints spans of a trace as a tree built from `parentSpanId`: a whole stored trace, or the spans of
 * one export request among the spans known so far.
 *
 * For each received span the tree shows:
 *  - its descendants received earlier, with full details: all of them for a root span, otherwise
 *    up to {@see self::INLINE_DESCENDANTS}, or just their number when there are more;
 *  - its nearest ancestor received earlier, dimmed and without details, with the number of ancestors above.
 * A parent that has not arrived is shown as `parent:<id>` on the topmost span.
 *
 * Output stays linear in the number of printed spans: indentation stops growing at {@see self::MAX_INDENT} levels.
 *
 * @internal
 */
final class TraceTree
{
    private const INLINE_DESCENDANTS = 16;
    private const MAX_INDENT = 24;

    /** Bounds the walks that only count spans. */
    private const MAX_COUNTED = 1000;

    /** @var array<non-empty-string, true> Span IDs already printed in the current trace */
    private array $rendered = [];

    /** Spans the current call may still print */
    private int $budget = 0;

    public function __construct(
        private readonly SpanStore $store,
    ) {}

    /**
     * Escapes received text for the console: Symfony markup like `<fg=foo>` would be parsed and may throw,
     * and control characters such as ANSI escape sequences would reach the terminal.
     */
    public static function text(string $value): string
    {
        $value = (string) \preg_replace_callback(
            '/[\x00-\x08\x0A-\x1F\x7F]|\xC2[\x80-\x9F]/',
            static fn(array $match): string => \strlen($match[0]) === 1
                ? \sprintf('\x%02X', \ord($match[0]))
                : \sprintf('\u{%04X}', \ord($match[0][1])),
            $value,
        );

        return OutputFormatter::escape($value);
    }

    public static function formatValue(mixed $value, bool $quoteString = true): string
    {
        if (\is_string($value) && !$quoteString) {
            return $value;
        }

        try {
            return Json::encode($value);
        } catch (\JsonException) {
            return \get_debug_type($value);
        }
    }

    /**
     * Prints every stored span of the trace in full.
     *
     * @return bool False for a trace the store does not hold.
     */
    public function renderStored(OutputInterface $output, string $traceId): bool
    {
        $spans = $this->store->getTrace($traceId);
        $bounds = $this->store->getTraceBounds($traceId);
        if ($spans === [] || $bounds === null) {
            return false;
        }

        // Not the given ID: the stored spans carry the ID in its canonical form.
        $traceId = \reset($spans)->traceId;
        $known = new KnownSpans(
            fn(string $spanId): ?Span => $this->store->get($traceId, $spanId),
            fn(string $spanId): array => $this->store->getChildren($traceId, $spanId),
            fn(string $spanId): int => $this->store->getChildSeq($traceId, $spanId) ?? 0,
            $bounds[0],
            $bounds[1],
            \count($spans),
        );
        $this->render($output, $traceId, $known, $spans, \PHP_INT_MAX);

        return true;
    }

    /**
     * @param non-empty-string $traceId
     * @param non-empty-array<non-empty-string, Span> $received Spans to print in full, all of `$known`.
     * @param int $budget The most spans to print.
     *
     * @return int The budget left.
     */
    public function render(OutputInterface $output, string $traceId, KnownSpans $known, array $received, int $budget): int
    {
        $this->budget = $budget;

        // Span ID => true for an ancestor shown as context only, false for a span shown in full.
        $shown = \array_fill_keys(\array_keys($received), false);
        /** @var array<non-empty-string, int> $collapsed Span ID => number of its hidden earlier descendants */
        $collapsed = [];
        // Array keys are not used as IDs here: PHP turns an all-digit hex ID into an int key.
        foreach ($received as $span) {
            $limit = $span->parentSpanId === null ? \PHP_INT_MAX : self::INLINE_DESCENDANTS + 1;
            $descendants = self::descendants($span->spanId, $known, $received, $limit);
            if (\count($descendants) <= self::INLINE_DESCENDANTS || $span->parentSpanId === null) {
                $shown += \array_fill_keys($descendants, false);
                continue;
            }

            $collapsed[$span->spanId] = \count(
                self::descendants($span->spanId, $known, $received, self::MAX_COUNTED),
            );
        }

        /** @var array<non-empty-string, int> $hiddenAncestors Context span ID => number of known ancestors above it */
        $hiddenAncestors = [];
        foreach ($received as $span) {
            $parentId = $span->parentSpanId;
            if ($parentId === null || isset($shown[$parentId])) {
                continue;
            }
            $parent = $known->get($parentId);
            if ($parent === null) {
                continue;
            }

            $shown[$parentId] = true;
            $above = 0;
            $ancestor = $parent->parentSpanId === null ? null : $known->get($parent->parentSpanId);
            while ($ancestor !== null && $above < self::MAX_COUNTED) {
                ++$above;
                $ancestor = $ancestor->parentSpanId === null ? null : $known->get($ancestor->parentSpanId);
            }
            $hiddenAncestors[$parentId] = $above;
        }

        $earlier = $known->count - \count($received);
        Common::renderHeader2(
            $output,
            "Trace $traceId",
            self::formatDuration(Span::elapsed($known->start, $known->end)),
            $earlier > 0 ? "+$earlier received earlier" : '',
        );

        $roots = [];
        // Collected from the shown spans, not from the list of all children of a parent: a parent shown
        // in every frame that brings one more of its children would make a series of frames quadratic.
        /** @var array<non-empty-string, list<Span>> $children */
        $children = [];
        // Keys of $shown are cast back to strings: an all-digit ID is an int key.
        foreach ($shown as $spanId => $_) {
            $span = $known->get((string) $spanId);
            \assert($span !== null);
            $parentId = $span->parentSpanId;
            if ($parentId !== null && isset($shown[$parentId])) {
                $children[$parentId][] = $span;
            } else {
                $roots[] = $span;
            }
        }
        foreach ($children as &$list) {
            \usort($list, static fn(Span $a, Span $b): int => $a->startTimeUnixNano <=> $b->startTimeUnixNano
                ?: $known->compareArrival($a, $b));
        }
        unset($list);

        $context = new TraceView($known, $shown, $collapsed, $hiddenAncestors, $children);
        $this->rendered = [];
        foreach (self::sortByStart($roots) as $root) {
            $this->renderSpan($output, $context, $root);
        }

        // Spans of a parent cycle have no root; such data is malformed, but every span is still printed.
        foreach ($shown as $spanId => $_) {
            if (!isset($this->rendered[$spanId])) {
                $span = $known->get((string) $spanId);
                \assert($span !== null);
                $this->renderSpan($output, $context, $span);
            }
        }

        $skipped = \count($shown) - \count($this->rendered);
        $skipped > 0 and $output->writeln(\sprintf(
            '<fg=gray>… %d more %s not shown</>',
            $skipped,
            $skipped === 1 ? 'span' : 'spans',
        ));

        return $this->budget;
    }

    /**
     * @param int<0, max> $nanoseconds
     */
    private static function formatDuration(int $nanoseconds): string
    {
        return match (true) {
            $nanoseconds < 1_000 => "$nanoseconds ns",
            $nanoseconds < 1_000_000 => \sprintf('%.2f µs', (float) $nanoseconds / 1e3),
            $nanoseconds < 1_000_000_000 => \sprintf('%.2f ms', (float) $nanoseconds / 1e6),
            default => \sprintf('%.2f s', (float) $nanoseconds / 1e9),
        };
    }

    /**
     * @param array<array-key, Span> $spans
     * @return list<Span>
     */
    private static function sortByStart(array $spans): array
    {
        \usort($spans, static fn(Span $a, Span $b): int => $a->startTimeUnixNano <=> $b->startTimeUnixNano);
        return $spans;
    }

    /**
     * Descendants of a span that are not in `$exclude`, in breadth-first order.
     *
     * @param array<non-empty-string, mixed> $exclude Span IDs to skip together with their subtrees.
     * @param int<1, max> $limit
     * @return list<non-empty-string> At most `$limit` span IDs.
     */
    private static function descendants(string $spanId, KnownSpans $known, array $exclude, int $limit): array
    {
        $result = [];
        $seen = [$spanId => true];
        $queue = [$spanId];
        // An index instead of array_shift(), which moves the whole queue on every call.
        for ($i = 0; $i < \count($queue); ++$i) {
            foreach ($known->children($queue[$i]) as $child) {
                if (isset($seen[$child->spanId]) || isset($exclude[$child->spanId])) {
                    continue;
                }

                $seen[$child->spanId] = true;
                $result[] = $child->spanId;
                if (\count($result) >= $limit) {
                    return $result;
                }

                $queue[] = $child->spanId;
            }
        }

        return $result;
    }

    private static function formatCount(int $count): string
    {
        return $count >= self::MAX_COUNTED ? self::MAX_COUNTED . '+' : (string) $count;
    }

    /**
     * Prints a span with its subtree depth-first. A stack instead of recursion, so that a deep chain
     * does not hit a nesting limit such as the one of Xdebug.
     */
    private function renderSpan(OutputInterface $output, TraceView $context, Span $root): void
    {
        /** @var list<array{Span, string, bool|null, int<0, max>}> $stack */
        $stack = [[$root, '', null, 0]];
        while (($item = \array_pop($stack)) !== null) {
            [$span, $prefix, $isLast, $depth] = $item;
            foreach (\array_reverse($this->renderOne($output, $context, $span, $prefix, $isLast, $depth)) as $child) {
                $stack[] = $child;
            }
        }
    }

    /**
     * @param bool|null $isLast Null for a root span.
     * @param int<0, max> $depth
     * @return list<array{Span, string, bool, int<0, max>}> Children to print next, in order.
     */
    private function renderOne(
        OutputInterface $output,
        TraceView $context,
        Span $span,
        string $prefix,
        ?bool $isLast,
        int $depth,
    ): array {
        if (isset($this->rendered[$span->spanId]) || $this->budget <= 0) {
            return [];
        }

        --$this->budget;
        $this->rendered[$span->spanId] = true;
        $isContext = $context->shown[$span->spanId] ?? false;
        $header = $isContext
            ? $this->formatContextHeader($span, $isLast === null, $context->hiddenAncestors[$span->spanId] ?? 0)
            : $this->formatSpanHeader($span, $context->known->start, $isLast === null);
        $depth > self::MAX_INDENT and $header .= " <fg=gray>[depth $depth]</>";
        $output->writeln($prefix . match ($isLast) {
            null => '',
            true => '└─ ',
            false => '├─ ',
        } . $header);

        $nested = $context->children[$span->spanId] ?? [];
        // Past the indentation limit children are drawn at their parent's level.
        $childPrefix = $depth >= self::MAX_INDENT ? $prefix : $prefix . match ($isLast) {
            null => '',
            true => '   ',
            false => '│  ',
        };
        $detailPrefix = $childPrefix . ($nested === [] ? '   ' : '│  ');

        foreach ($isContext ? [] : $this->formatDetails($span) as $line) {
            $output->writeln($detailPrefix . $line);
        }

        isset($context->collapsed[$span->spanId]) and $output->writeln(\sprintf(
            '%s<fg=gray>… %s earlier descendants</>',
            $detailPrefix,
            self::formatCount($context->collapsed[$span->spanId]),
        ));

        $last = \count($nested) - 1;
        $result = [];
        foreach ($nested as $i => $child) {
            $result[] = [$child, $childPrefix, $i === $last, $depth + 1];
        }

        return $result;
    }

    private function formatContextHeader(Span $span, bool $isRoot, int $ancestorsAbove): string
    {
        return \sprintf(
            '<fg=gray>%s %s %s id:%s%s%s</>',
            self::text($span->name),
            \strtoupper($span->kind->name),
            self::formatDuration($span->getDuration()),
            $span->spanId,
            $isRoot && $span->parentSpanId !== null && $ancestorsAbove === 0 ? " parent:$span->parentSpanId" : '',
            match (true) {
                $ancestorsAbove === 0 => '',
                $ancestorsAbove === 1 => ' (1 more ancestor above)',
                default => ' (' . self::formatCount($ancestorsAbove) . ' more ancestors above)',
            },
        );
    }

    private function formatSpanHeader(Span $span, int $traceStart, bool $isRoot): string
    {
        $parts = [
            \sprintf('<options=bold>%s</>', self::text($span->name)),
            \sprintf('<fg=cyan>%s</>', \strtoupper($span->kind->name)),
            \sprintf('<fg=yellow>%s</>', self::formatDuration($span->getDuration())),
        ];

        $span->status->code === StatusCode::Unset or $parts[] = $span->status->code === StatusCode::Ok
            ? '<fg=green>OK</>'
            : \sprintf(
                '<fg=red;options=bold>ERROR%s</>',
                $span->status->message === '' ? '' : ': ' . self::text($span->status->message),
            );

        $offset = Span::elapsed($traceStart, $span->startTimeUnixNano);
        $parts[] = \sprintf(
            '<fg=gray>%sid:%s%s</>',
            $offset === 0 ? '' : '+' . self::formatDuration($offset) . ' ',
            $span->spanId,
            $isRoot && $span->parentSpanId !== null ? " parent:$span->parentSpanId" : '',
        );

        return \implode(' ', $parts);
    }

    /**
     * @return list<string>
     */
    private function formatDetails(Span $span): array
    {
        $lines = [];
        foreach ($span->attributes as $key => $value) {
            $lines[] = \sprintf(
                '<fg=gray>%s:</> %s',
                self::text((string) $key),
                self::text(self::formatValue($value)),
            );
        }

        foreach ($span->events as $event) {
            $attributes = [];
            foreach ($event->attributes as $key => $value) {
                $attributes[] = \sprintf('%s=%s', $key, self::formatValue($value));
            }

            $lines[] = \sprintf(
                '<fg=magenta>• %s</> <fg=gray>+%s</> %s',
                self::text($event->name),
                self::formatDuration(Span::elapsed($span->startTimeUnixNano, $event->timeUnixNano)),
                self::text(\implode(' ', $attributes)),
            );
        }

        foreach ($span->links as $link) {
            $lines[] = \sprintf('<fg=blue>→ link</> <fg=gray>%s/%s</>', $link->traceId, $link->spanId);
        }

        return $lines;
    }
}
