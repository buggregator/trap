<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Bench\Module\Tracing;

use Buggregator\Trap\Module\Tracing\Config\Traces;
use Buggregator\Trap\Module\Tracing\Console\Renderer;
use Buggregator\Trap\Module\Tracing\Frame\OtlpTraces;
use Buggregator\Trap\Module\Tracing\Frame\Trace\ResourceSpans;
use Buggregator\Trap\Module\Tracing\Frame\Trace\ScopeSpans;
use Buggregator\Trap\Module\Tracing\Frame\Trace\Span;
use Buggregator\Trap\Module\Tracing\Frame\Trace\SpanKind;
use Symfony\Component\Console\Output\BufferedOutput;
use Testo\Bench;

/**
 * A console frame with one new child of a parent that has already received many children, as a simple
 * span processor sends them: each frame prints the parent as context. The cost of a frame should not
 * grow with the number of earlier children.
 *
 * The scenarios are compared to each other, not gated: `tolerance` is infinite. Each renderer is filled
 * on the warmup call and kept; every measured call adds one more child.
 */
final class RenderChildrenBench
{
    private const TRACE = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /** @var array<int, array{Renderer, int}> Earlier children => renderer and the ID of the next child */
    private static array $renderers = [];

    #[Bench(
        callables: [
            '10k earlier children' => [self::class, 'children10k'],
            '100k earlier children' => [self::class, 'children100k'],
        ],
        calls: 100,
        iterations: 5,
        tolerance: \INF,
    )]
    public static function children1k(): void
    {
        self::renderNextChild(1_000);
    }

    public static function children10k(): void
    {
        self::renderNextChild(10_000);
    }

    public static function children100k(): void
    {
        self::renderNextChild(100_000);
    }

    /**
     * @param positive-int $earlier
     */
    private static function renderNextChild(int $earlier): void
    {
        if (!isset(self::$renderers[$earlier])) {
            $config = new Traces();
            $config->maxSpans = 1_000_000;
            $renderer = new Renderer($config);
            $renderer->render(new BufferedOutput(), self::frame([self::span(1, null)]));
            $renderer->render(new BufferedOutput(), self::frame(\array_map(
                static fn(int $id): Span => self::span($id, 1),
                \range(2, $earlier + 1),
            )));
            self::$renderers[$earlier] = [$renderer, $earlier + 2];
        }

        [$renderer, $id] = self::$renderers[$earlier];
        self::$renderers[$earlier][1] = $id + 1;
        $renderer->render(new BufferedOutput(), self::frame([self::span($id, 1)]));
    }

    /**
     * @param list<Span> $spans
     */
    private static function frame(array $spans): OtlpTraces
    {
        return new OtlpTraces([new ResourceSpans([new ScopeSpans($spans)])]);
    }

    private static function span(int $id, ?int $parent): Span
    {
        return new Span(
            traceId: self::TRACE,
            spanId: \sprintf('%016x', $id),
            parentSpanId: $parent === null ? null : \sprintf('%016x', $parent),
            name: "span-$id",
            kind: SpanKind::Internal,
            startTimeUnixNano: $id,
            endTimeUnixNano: $id + 10,
        );
    }
}
