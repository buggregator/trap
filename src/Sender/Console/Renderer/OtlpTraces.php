<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender\Console\Renderer;

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
 * Renders spans grouped by trace, as a tree built from `parentSpanId`.
 *
 * A frame holds one export request, so a span whose parent was exported in another request
 * is rendered as a root with its parent ID. With a simple span processor every span arrives alone.
 *
 * @implements Renderer<Frame\Otlp\OtlpTraces>
 *
 * @internal
 */
final class OtlpTraces implements Renderer
{
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
        /** @var array<non-empty-string, non-empty-list<Span>> $traces */
        $traces = [];
        foreach ($frame->iterateSpans() as [, $scopeSpans, $span]) {
            $scopeSpans->scopeName === '' or $scopes[] = \trim("$scopeSpans->scopeName $scopeSpans->scopeVersion");
            $traces[$span->traceId][] = $span;
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
     * @param non-empty-string $traceId
     * @param non-empty-list<Span> $spans
     */
    private function renderTrace(OutputInterface $output, string $traceId, array $spans): void
    {
        \usort($spans, static fn(Span $a, Span $b): int => $a->startTimeUnixNano <=> $b->startTimeUnixNano);
        $start = $spans[0]->startTimeUnixNano;
        $end = \max(\array_map(static fn(Span $span): int => $span->endTimeUnixNano, $spans));

        Common::renderHeader2($output, "Trace $traceId", self::formatDuration(\max(0, $end - $start)));

        $known = [];
        foreach ($spans as $span) {
            $known[$span->spanId] = true;
        }

        $roots = [];
        /** @var array<non-empty-string, list<Span>> $children */
        $children = [];
        foreach ($spans as $span) {
            $span->parentSpanId !== null && isset($known[$span->parentSpanId])
                ? $children[$span->parentSpanId][] = $span
                : $roots[] = $span;
        }

        $rendered = [];
        foreach ($roots as $root) {
            $this->renderSpan($output, $root, $children, $start, '', null, $rendered);
        }

        // Spans of a parent cycle have no root; such data is malformed, but every span is still printed.
        foreach ($spans as $span) {
            isset($rendered[$span->spanId])
            or $this->renderSpan($output, $span, $children, $start, '', null, $rendered);
        }
    }

    /**
     * @param array<non-empty-string, list<Span>> $children
     * @param bool|null $isLast Null for a root span.
     * @param array<non-empty-string, true> $rendered Span IDs already printed.
     */
    private function renderSpan(
        OutputInterface $output,
        Span $span,
        array $children,
        int $traceStart,
        string $prefix,
        ?bool $isLast,
        array &$rendered,
    ): void {
        if (isset($rendered[$span->spanId])) {
            return;
        }

        $rendered[$span->spanId] = true;
        $output->writeln($prefix . match ($isLast) {
            null => '',
            true => '└─ ',
            false => '├─ ',
        } . $this->formatSpanHeader($span, $traceStart, $isLast === null));

        $nested = $children[$span->spanId] ?? [];
        $childPrefix = $prefix . match ($isLast) {
            null => '',
            true => '   ',
            false => '│  ',
        };
        $detailPrefix = $childPrefix . ($nested === [] ? '   ' : '│  ');

        foreach ($this->formatDetails($span) as $line) {
            $output->writeln($detailPrefix . $line);
        }

        $last = \count($nested) - 1;
        foreach ($nested as $i => $child) {
            $this->renderSpan($output, $child, $children, $traceStart, $childPrefix, $i === $last, $rendered);
        }
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
