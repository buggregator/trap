<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender\Console\Renderer;

use Buggregator\Trap\Module\Tracing\SpanStore;
use Buggregator\Trap\Proto\Frame;
use Buggregator\Trap\Proto\Frame\Otlp\Trace\ResourceSpans;
use Buggregator\Trap\Proto\Frame\Otlp\Trace\Span;
use Buggregator\Trap\Proto\Frame\Otlp\Trace\StatusCode;
use Buggregator\Trap\ProtoType;
use Buggregator\Trap\Sender\Console\Renderer;
use Buggregator\Trap\Sender\Console\Renderer\Otlp\TraceView;
use Buggregator\Trap\Sender\Console\Support\Common;
use Buggregator\Trap\Support\Json;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Renders received spans grouped by trace, as a tree built from `parentSpanId`.
 *
 * Every received span is added to the {@see SpanStore}, so a tree can join spans of separate
 * export requests. For each newly received span the tree shows:
 *  - its descendants received earlier, with full details: all of them for a root span, otherwise
 *    up to {@see self::INLINE_DESCENDANTS}, or just their number when there are more;
 *  - its nearest ancestor received earlier, dimmed and without details, with the number of ancestors above.
 * With a simple span processor children end before their parents, so each span is printed when
 * it arrives, and the root span brings the complete tree. A parent that has not arrived is shown as
 * `parent:<id>` on the topmost span.
 *
 * Output stays linear in the number of received spans: indentation stops growing at
 * {@see self::MAX_INDENT} levels, and a frame prints at most {@see self::MAX_SPANS} spans.
 *
 * @implements Renderer<Frame\Otlp\OtlpTraces>
 *
 * @internal
 */
final class OtlpTraces implements Renderer
{
    private const INLINE_DESCENDANTS = 16;
    private const MAX_INDENT = 24;
    private const MAX_SPANS = 500;

    /** Bounds the walks that only count spans. */
    private const MAX_COUNTED = 1000;

    /** @var array<non-empty-string, true> Span IDs already printed in the current trace */
    private array $rendered = [];

    /** Spans the current frame may still print */
    private int $budget = 0;

    public function __construct(
        private readonly SpanStore $store = new SpanStore(),
    ) {}

    #[\Override]
    public function isSupport(Frame $frame): bool
    {
        return $frame->type === ProtoType::Otlp && $frame instanceof Frame\Otlp\OtlpTraces;
    }

    #[\Override]
    public function render(OutputInterface $output, Frame $frame): void
    {
        \assert($frame instanceof Frame\Otlp\OtlpTraces);

        $count = $frame->countSpans();
        Common::renderHeader1($output, 'OTLP TRACES', ...['gray' => $count === 1 ? '1 span' : "$count spans"]);

        $services = \array_unique(\array_filter(\array_map(
            static fn(ResourceSpans $resource): ?string => $resource->getServiceName(),
            $frame->resourceSpans,
        )));
        $scopes = [];
        /** @var array<non-empty-string, non-empty-array<non-empty-string, Span>> $traces */
        $traces = [];
        foreach ($frame->iterateSpans() as [, $scopeSpans, $span]) {
            $scopeSpans->scopeName === '' or $scopes[] = \trim("$scopeSpans->scopeName $scopeSpans->scopeVersion");
            $traces[$span->traceId][$span->spanId] = $span;
            $this->store->add($span);
        }

        Common::renderMetadata($output, \array_filter([
            'Time' => $frame->time,
            'Service' => \implode(', ', $services),
            'Scope' => \implode(', ', \array_unique($scopes)),
        ], static fn(mixed $value): bool => $value !== ''));

        // Resource attributes repeat in every export request; with a simple span processor that is every span.
        foreach ($output->isVerbose() ? $frame->resourceSpans : [] as $resource) {
            $tags = [];
            foreach ($resource->resourceAttributes as $key => $value) {
                $tags[(string) $key] = self::formatValue($value, quoteString: false);
            }
            if ($tags !== []) {
                $output->writeln('');
                Common::renderTags($output, $tags);
            }
        }

        $this->budget = self::MAX_SPANS;
        foreach ($traces as $spans) {
            // Not the array key: PHP turns an all-digit hex ID into an int key.
            $this->renderTrace($output, \reset($spans)->traceId, $spans);
        }
    }

    private static function formatValue(mixed $value, bool $quoteString = true): string
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
     * @param array<non-empty-string, list<Span>> $children
     * @param array<non-empty-string, mixed> $exclude Span IDs to skip together with their subtrees.
     * @param int<1, max> $limit
     * @return list<non-empty-string> At most `$limit` span IDs.
     */
    private static function descendants(string $spanId, array $children, array $exclude, int $limit): array
    {
        $result = [];
        $seen = [$spanId => true];
        $queue = [$spanId];
        while (($current = \array_shift($queue)) !== null) {
            foreach ($children[$current] ?? [] as $child) {
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
     * @param non-empty-string $traceId
     * @param non-empty-array<non-empty-string, Span> $received Spans of the current frame.
     */
    private function renderTrace(OutputInterface $output, string $traceId, array $received): void
    {
        // The store may have evicted the trace already when it alone exceeds the store capacity.
        $known = $this->store->getTrace($traceId) + $received;

        /** @var array<non-empty-string, list<Span>> $children */
        $children = [];
        foreach ($known as $span) {
            $span->parentSpanId !== null && isset($known[$span->parentSpanId])
            and $children[$span->parentSpanId][] = $span;
        }

        // Span ID => true for an ancestor shown as context only, false for a span shown in full.
        $shown = \array_fill_keys(\array_keys($received), false);
        /** @var array<non-empty-string, int> $collapsed Span ID => number of its hidden earlier descendants */
        $collapsed = [];
        // Array keys are not used as IDs here: PHP turns an all-digit hex ID into an int key.
        foreach ($received as $span) {
            $limit = $span->parentSpanId === null ? \PHP_INT_MAX : self::INLINE_DESCENDANTS + 1;
            $descendants = self::descendants($span->spanId, $children, $received, $limit);
            if (\count($descendants) <= self::INLINE_DESCENDANTS || $span->parentSpanId === null) {
                $shown += \array_fill_keys($descendants, false);
                continue;
            }

            $collapsed[$span->spanId] = \count(
                self::descendants($span->spanId, $children, $received, self::MAX_COUNTED),
            );
        }

        /** @var array<non-empty-string, int> $hiddenAncestors Context span ID => number of known ancestors above it */
        $hiddenAncestors = [];
        foreach ($received as $span) {
            $parentId = $span->parentSpanId;
            if ($parentId === null || !isset($known[$parentId]) || isset($shown[$parentId])) {
                continue;
            }

            $shown[$parentId] = true;
            $above = 0;
            $ancestorId = $known[$parentId]->parentSpanId;
            while ($ancestorId !== null && isset($known[$ancestorId]) && $above < self::MAX_COUNTED) {
                ++$above;
                $ancestorId = $known[$ancestorId]->parentSpanId;
            }
            $hiddenAncestors[$parentId] = $above;
        }

        $start = \min(\array_map(static fn(Span $span): int => $span->startTimeUnixNano, $known));
        $end = \max(\array_map(static fn(Span $span): int => $span->endTimeUnixNano, $known));
        $earlier = \count($known) - \count($received);
        Common::renderHeader2(
            $output,
            "Trace $traceId",
            self::formatDuration(Span::elapsed($start, $end)),
            $earlier > 0 ? "+$earlier received earlier" : '',
        );

        $roots = [];
        foreach ($shown as $spanId => $_) {
            $parentId = $known[$spanId]->parentSpanId;
            $parentId !== null && isset($shown[$parentId]) or $roots[] = $known[$spanId];
        }

        $context = new TraceView($children, $shown, $collapsed, $hiddenAncestors, $start);
        $this->rendered = [];
        foreach (self::sortByStart($roots) as $root) {
            $this->renderSpan($output, $context, $root);
        }

        // Spans of a parent cycle have no root; such data is malformed, but every span is still printed.
        foreach ($shown as $spanId => $_) {
            isset($this->rendered[$spanId]) or $this->renderSpan($output, $context, $known[$spanId]);
        }

        $skipped = \count($shown) - \count($this->rendered);
        $skipped > 0 and $output->writeln(\sprintf('<fg=gray>… %d more spans not shown</>', $skipped));
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
            : $this->formatSpanHeader($span, $context->traceStart, $isLast === null);
        $depth > self::MAX_INDENT and $header .= " <fg=gray>[depth $depth]</>";
        $output->writeln($prefix . match ($isLast) {
            null => '',
            true => '└─ ',
            false => '├─ ',
        } . $header);

        $nested = self::sortByStart(\array_filter(
            $context->children[$span->spanId] ?? [],
            static fn(Span $child): bool => isset($context->shown[$child->spanId]),
        ));
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
            OutputFormatter::escape($span->name),
            \strtoupper($span->kind->name),
            self::formatDuration($span->getDuration()),
            $span->spanId,
            $isRoot && $span->parentSpanId !== null && $ancestorsAbove === 0 ? " parent:$span->parentSpanId" : '',
            $ancestorsAbove > 0 ? ' (' . self::formatCount($ancestorsAbove) . ' more ancestors above)' : '',
        );
    }

    private function formatSpanHeader(Span $span, int $traceStart, bool $isRoot): string
    {
        $parts = [
            \sprintf('<options=bold>%s</>', OutputFormatter::escape($span->name)),
            \sprintf('<fg=cyan>%s</>', \strtoupper($span->kind->name)),
            \sprintf('<fg=yellow>%s</>', self::formatDuration($span->getDuration())),
        ];

        $span->status->code === StatusCode::Unset or $parts[] = $span->status->code === StatusCode::Ok
            ? '<fg=green>OK</>'
            : \sprintf(
                '<fg=red;options=bold>ERROR%s</>',
                $span->status->message === '' ? '' : ': ' . OutputFormatter::escape($span->status->message),
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
                OutputFormatter::escape((string) $key),
                OutputFormatter::escape(self::formatValue($value)),
            );
        }

        foreach ($span->events as $event) {
            $attributes = [];
            foreach ($event->attributes as $key => $value) {
                $attributes[] = \sprintf('%s=%s', $key, self::formatValue($value));
            }

            $lines[] = \sprintf(
                '<fg=magenta>• %s</> <fg=gray>+%s</> %s',
                OutputFormatter::escape($event->name),
                self::formatDuration(Span::elapsed($span->startTimeUnixNano, $event->timeUnixNano)),
                OutputFormatter::escape(\implode(' ', $attributes)),
            );
        }

        foreach ($span->links as $link) {
            $lines[] = \sprintf('<fg=blue>→ link</> <fg=gray>%s/%s</>', $link->traceId, $link->spanId);
        }

        return $lines;
    }
}
