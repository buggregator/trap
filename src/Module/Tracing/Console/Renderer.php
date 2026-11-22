<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Tracing\Console;

use Buggregator\Trap\Module\Console\Renderer as RendererInterface;
use Buggregator\Trap\Module\Console\Support\Common;
use Buggregator\Trap\Module\Tracing\Config\Traces;
use Buggregator\Trap\Module\Tracing\Frame\OtlpTraces;
use Buggregator\Trap\Module\Tracing\Frame\Trace\ResourceSpans;
use Buggregator\Trap\Module\Tracing\Frame\Trace\Span;
use Buggregator\Trap\Server\Proto\Frame;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Renders received spans grouped by trace, each trace as a {@see TraceTree} of the spans of the frame
 * among the spans received so far.
 *
 * Every rendered span is added to a {@see SpanBuffer} of the renderer, so a tree can join spans of separate
 * export requests. With a simple span processor children end before their parents, so each span is printed
 * when it arrives, and the root span brings the complete tree.
 *
 * A frame prints at most {@see self::MAX_SPANS} spans.
 *
 * @implements RendererInterface<OtlpTraces>
 *
 * @internal
 */
final class Renderer implements RendererInterface
{
    private const MAX_SPANS = 500;

    private readonly SpanBuffer $store;
    private readonly TraceTree $tree;

    public function __construct(Traces $config = new Traces())
    {
        $this->store = new SpanBuffer($config);
        $this->tree = new TraceTree($this->store);
    }

    #[\Override]
    public function isSupport(Frame $frame): bool
    {
        return $frame instanceof OtlpTraces;
    }

    #[\Override]
    public function render(OutputInterface $output, Frame $frame): void
    {
        \assert($frame instanceof OtlpTraces);

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
            $scopeSpans->scopeName === ''
                or $scopes[] = TraceTree::text(\trim("$scopeSpans->scopeName $scopeSpans->scopeVersion"));
            $traces[$span->traceId][$span->spanId] = $span;
            $this->store->add($span);
        }

        Common::renderMetadata($output, \array_filter([
            'Time' => $frame->time,
            'Service' => TraceTree::text(\implode(', ', $services)),
            'Scope' => \implode(', ', \array_unique($scopes)),
        ], static fn(mixed $value): bool => $value !== ''));

        // Resource attributes repeat in every export request; with a simple span processor that is every span.
        foreach ($output->isVerbose() ? $frame->resourceSpans : [] as $resource) {
            $tags = [];
            foreach ($resource->resourceAttributes as $key => $value) {
                $tags[TraceTree::text((string) $key)] = TraceTree::text(TraceTree::formatValue($value, quoteString: false));
            }
            if ($tags !== []) {
                $output->writeln('');
                Common::renderTags($output, $tags);
            }
        }

        $budget = self::MAX_SPANS;
        foreach ($traces as $spans) {
            // Not the array key: PHP turns an all-digit hex ID into an int key.
            $traceId = \reset($spans)->traceId;
            $budget = $this->tree->render($output, $traceId, $this->knownSpans($traceId, $spans), $spans, $budget);
        }
    }

    /**
     * The spans of a trace known so far: the stored ones and the received ones.
     *
     * Usually the store holds all received spans and answers from its index. When it has dropped some
     * of them already, because the trace alone exceeds the store capacity, the known spans are put
     * together from the stored trace and the frame.
     *
     * @param non-empty-string $traceId
     * @param non-empty-array<non-empty-string, Span> $received
     */
    private function knownSpans(string $traceId, array $received): KnownSpans
    {
        $stored = true;
        foreach ($received as $span) {
            if ($this->store->get($traceId, $span->spanId) === null) {
                $stored = false;
                break;
            }
        }

        $bounds = $stored ? $this->store->getTraceBounds($traceId) : null;
        if ($bounds !== null) {
            return new KnownSpans(
                fn(string $spanId): ?Span => $this->store->get($traceId, $spanId),
                fn(string $spanId): array => $this->store->getChildren($traceId, $spanId),
                fn(string $spanId): int => $this->store->getChildSeq($traceId, $spanId) ?? 0,
                $bounds[0],
                $bounds[1],
                $this->store->countTraceSpans($traceId),
            );
        }

        $known = $this->store->getTrace($traceId) + $received;
        /** @var array<non-empty-string, list<Span>> $children */
        $children = [];
        /** @var array<non-empty-string, int> $arrival */
        $arrival = [];
        foreach ($known as $span) {
            $arrival[$span->spanId] = \count($arrival);
            $span->parentSpanId !== null && isset($known[$span->parentSpanId])
            and $children[$span->parentSpanId][] = $span;
        }

        return new KnownSpans(
            static fn(string $spanId): ?Span => $known[$spanId] ?? null,
            static fn(string $spanId): array => $children[$spanId] ?? [],
            static fn(string $spanId): int => $arrival[$spanId] ?? 0,
            \min(\array_map(static fn(Span $span): int => $span->startTimeUnixNano, $known)),
            \max(\array_map(static fn(Span $span): int => $span->endTimeUnixNano, $known)),
            \count($known),
        );
    }
}
