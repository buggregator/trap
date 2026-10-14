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
use Buggregator\Trap\Sender\Console\Support\Common;
use Buggregator\Trap\Support\Json;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Renders received spans grouped by trace, as a tree built from `parentSpanId`.
 *
 * Every received span is added to the {@see SpanStore}, so a tree can join spans of separate
 * export requests. For each newly received span the tree shows:
 *  - its whole subtree, including descendants received earlier, with full details;
 *  - its ancestors received earlier, dimmed and without details, as the path to the root.
 * With a simple span processor children end before their parents, so each span is printed alone when
 * it arrives, and the root span brings the complete tree. A parent that has not arrived is shown as
 * `parent:<id>` on the topmost span.
 *
 * @implements Renderer<Frame\Otlp\OtlpTraces>
 *
 * @internal
 */
final class OtlpTraces implements Renderer
{
    /** @var array<non-empty-string, true> Span IDs already printed in the current trace */
    private array $rendered = [];

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

        foreach ($traces as $traceId => $spans) {
            $this->renderTrace($output, $traceId, $spans);
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
        $shown = [];
        $queue = \array_keys($received);
        while ($queue !== []) {
            $spanId = \array_shift($queue);
            if (isset($shown[$spanId])) {
                continue;
            }

            $shown[$spanId] = false;
            foreach ($children[$spanId] ?? [] as $child) {
                $queue[] = $child->spanId;
            }
        }

        foreach ($received as $span) {
            $parentId = $span->parentSpanId;
            while ($parentId !== null && isset($known[$parentId]) && !isset($shown[$parentId])) {
                $shown[$parentId] = true;
                $parentId = $known[$parentId]->parentSpanId;
            }
        }

        $start = \min(\array_map(static fn(Span $span): int => $span->startTimeUnixNano, $known));
        $end = \max(\array_map(static fn(Span $span): int => $span->endTimeUnixNano, $known));
        $earlier = \count($known) - \count($received);
        Common::renderHeader2(
            $output,
            "Trace $traceId",
            self::formatDuration(\max(0, $end - $start)),
            $earlier > 0 ? "+$earlier received earlier" : '',
        );

        $roots = [];
        foreach ($shown as $spanId => $_) {
            $parentId = $known[$spanId]->parentSpanId;
            $parentId !== null && isset($shown[$parentId]) or $roots[] = $known[$spanId];
        }

        $this->rendered = [];
        foreach (self::sortByStart($roots) as $root) {
            $this->renderSpan($output, $root, $children, $shown, $start, '', null);
        }

        // Spans of a parent cycle have no root; such data is malformed, but every span is still printed.
        foreach ($shown as $spanId => $_) {
            isset($this->rendered[$spanId])
            or $this->renderSpan($output, $known[$spanId], $children, $shown, $start, '', null);
        }
    }

    /**
     * @param array<non-empty-string, list<Span>> $children
     * @param array<non-empty-string, bool> $shown Span ID => whether the span is context only.
     * @param bool|null $isLast Null for a root span.
     */
    private function renderSpan(
        OutputInterface $output,
        Span $span,
        array $children,
        array $shown,
        int $traceStart,
        string $prefix,
        ?bool $isLast,
    ): void {
        if (isset($this->rendered[$span->spanId])) {
            return;
        }

        $this->rendered[$span->spanId] = true;
        $isContext = $shown[$span->spanId] ?? false;
        $output->writeln($prefix . match ($isLast) {
            null => '',
            true => '└─ ',
            false => '├─ ',
        } . ($isContext
            ? $this->formatContextHeader($span, $isLast === null)
            : $this->formatSpanHeader($span, $traceStart, $isLast === null)));

        $nested = self::sortByStart(\array_filter(
            $children[$span->spanId] ?? [],
            static fn(Span $child): bool => isset($shown[$child->spanId]),
        ));
        $childPrefix = $prefix . match ($isLast) {
            null => '',
            true => '   ',
            false => '│  ',
        };
        $detailPrefix = $childPrefix . ($nested === [] ? '   ' : '│  ');

        foreach ($isContext ? [] : $this->formatDetails($span) as $line) {
            $output->writeln($detailPrefix . $line);
        }

        $last = \count($nested) - 1;
        foreach ($nested as $i => $child) {
            $this->renderSpan($output, $child, $children, $shown, $traceStart, $childPrefix, $i === $last);
        }
    }

    private function formatContextHeader(Span $span, bool $isRoot): string
    {
        return \sprintf(
            '<fg=gray>%s %s %s id:%s%s</>',
            OutputFormatter::escape($span->name),
            \strtoupper($span->kind->name),
            self::formatDuration($span->getDuration()),
            $span->spanId,
            $isRoot && $span->parentSpanId !== null ? " parent:$span->parentSpanId" : '',
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

        $offset = \max(0, $span->startTimeUnixNano - $traceStart);
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
                self::formatDuration(\max(0, $event->timeUnixNano - $span->startTimeUnixNano)),
                OutputFormatter::escape(\implode(' ', $attributes)),
            );
        }

        foreach ($span->links as $link) {
            $lines[] = \sprintf('<fg=blue>→ link</> <fg=gray>%s/%s</>', $link->traceId, $link->spanId);
        }

        return $lines;
    }
}
