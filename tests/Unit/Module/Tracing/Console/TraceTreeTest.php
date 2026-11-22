<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Tracing\Console;

use Buggregator\Trap\Module\Tracing\Console\Renderer;
use Buggregator\Trap\Module\Tracing\Console\SpanBuffer;
use Buggregator\Trap\Module\Tracing\Console\TraceTree;
use Buggregator\Trap\Module\Tracing\Frame\Trace\Span;
use Buggregator\Trap\Module\Tracing\Frame\Trace\SpanKind;
use Buggregator\Trap\Module\Tracing\Http\OtlpTrap\JsonTracesDecoder;
use Buggregator\Trap\Server\Info;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Covers(TraceTree::class)]
#[Test]
final class TraceTreeTest
{
    private const TRACE = '382bfca057473920912cab0a4ed95abe';

    public function testStoredTraceIsTheTreeTheConsolePrints(): void
    {
        $frame = (new JsonTracesDecoder())->decode(
            (string) \file_get_contents(Info::TRAP_ROOT . '/tests/Stub/otlp-traces.json'),
            new \DateTimeImmutable('2024-01-01 12:00:00'),
        );
        $store = new SpanBuffer();
        foreach ($frame->iterateSpans() as [, , $span]) {
            $store->add($span);
        }
        $console = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
        (new Renderer())->render($console, $frame);
        $console = $console->fetch();

        $text = self::plain($store, self::TRACE);

        Assert::string($console)->contains("\e[");
        Assert::string($text)->notContains("\e")->notContains('<fg=');
        Assert::string($text)->startsWith("\n# Trace " . self::TRACE . "  27.69 ms \n\nGET /orders SERVER 27.69 ms OK id:286e73f2056fe20e\n");
        // The console output past its frame header is the tree.
        Assert::string($console)->ignoringAnsi()->ignoringLineEndings()->endsWith($text);
    }

    public function testStoredTraceShowsEverySpanInFull(): void
    {
        $store = new SpanBuffer();
        $leaves = \array_map(static fn(int $i): Span => self::span(100 + $i, 2), \range(0, 29));
        $store->add(...$leaves);
        $store->add(self::span(2, 1), self::span(1, null));

        $text = self::plain($store, \str_repeat('a', 32));

        Assert::same(\substr_count($text, ' id:'), 32);
        Assert::same(\substr_count($text, 'n: '), 32, 'Every span with its details.');
        Assert::string($text)->notContains('earlier')->notContains('not shown');
    }

    public function testUnknownTraceRendersNothing(): void
    {
        $output = new BufferedOutput();

        $found = (new TraceTree(new SpanBuffer()))->renderStored($output, self::TRACE);

        Assert::false($found);
        Assert::same($output->fetch(), '');
    }

    private static function plain(SpanBuffer $store, string $traceId): string
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, false);
        Assert::true((new TraceTree($store))->renderStored($output, $traceId));

        return \str_replace("\r\n", "\n", $output->fetch());
    }

    private static function span(int $id, ?int $parent): Span
    {
        return new Span(
            traceId: \str_repeat('a', 32),
            spanId: \sprintf('%016x', $id),
            parentSpanId: $parent === null ? null : \sprintf('%016x', $parent),
            name: "span-$id",
            kind: SpanKind::Internal,
            startTimeUnixNano: $id,
            endTimeUnixNano: 1000,
            attributes: ['n' => $id],
        );
    }
}
