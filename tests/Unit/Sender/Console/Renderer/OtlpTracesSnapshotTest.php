<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Sender\Console\Renderer;

use Buggregator\Trap\Config\Server\Traces;
use Buggregator\Trap\Info;
use Buggregator\Trap\Module\Tracing\SpanStore;
use Buggregator\Trap\Proto\Frame\Otlp\OtlpTraces as OtlpTracesFrame;
use Buggregator\Trap\Proto\Frame\Otlp\Trace\ResourceSpans;
use Buggregator\Trap\Proto\Frame\Otlp\Trace\ScopeSpans;
use Buggregator\Trap\Proto\Frame\Otlp\Trace\Span;
use Buggregator\Trap\Proto\Frame\Otlp\Trace\SpanKind;
use Buggregator\Trap\Sender\Console\Renderer\OtlpTraces;
use Symfony\Component\Console\Output\BufferedOutput;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Lifecycle\BeforeClass;
use Testo\Test;

/**
 * Renders sequences of frames and compares the whole console output with a stored snapshot.
 *
 * Set `TRAP_UPDATE_SNAPSHOTS=1` to write the snapshots from the current renderer.
 */
#[Covers(OtlpTraces::class)]
#[Covers(SpanStore::class)]
#[Test]
final class OtlpTracesSnapshotTest
{
    private const SNAPSHOTS = Info::TRAP_ROOT . '/tests/Stub/otlp-render';
    private const TRACE_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const TRACE_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const TRACE_C = 'cccccccccccccccccccccccccccccccc';

    /**
     * @return \Generator<string, array{string, int, list<list<Span>>}> Scenario name, store span limit and frames.
     */
    public static function provideScenarios(): \Generator
    {
        foreach (self::scenarios() as $name => $arguments) {
            yield $name => [$name, ...$arguments];
        }
    }

    #[DataProvider('provideScenarios')]
    public function testOutputMatchesSnapshot(string $scenario, int $maxSpans, array $frames): void
    {
        $config = new Traces();
        $config->maxSpans = $maxSpans;
        $renderer = new OtlpTraces(new SpanStore($config));
        $output = new BufferedOutput();
        foreach ($frames as $i => $spans) {
            $output->writeln("=== frame $i");
            $renderer->render($output, new OtlpTracesFrame(
                [new ResourceSpans([new ScopeSpans($spans)])],
                new \DateTimeImmutable('2024-01-01 12:00:00'),
            ));
        }
        $actual = \str_replace("\r\n", "\n", $output->fetch());

        $file = self::snapshotFile($scenario);
        if (\getenv('TRAP_UPDATE_SNAPSHOTS')) {
            \is_dir(self::SNAPSHOTS) or \mkdir(self::SNAPSHOTS, 0777, true);
            \file_put_contents($file, $actual);
        }

        Assert::true(\file_exists($file));
        // A checkout with `core.autocrlf` may have turned the line endings into CRLF.
        Assert::same($actual, \str_replace("\r\n", "\n", (string) \file_get_contents($file)));
    }

    /**
     * Removes snapshots of scenarios that no longer exist before they are written again.
     */
    #[BeforeClass]
    public static function setUpBeforeClass(): void
    {
        if (!\getenv('TRAP_UPDATE_SNAPSHOTS')) {
            return;
        }

        $current = [];
        foreach (self::scenarios() as $name => $_) {
            $current[self::snapshotFile($name)] = true;
        }
        foreach (\glob(self::SNAPSHOTS . '/*.txt') ?: [] as $file) {
            isset($current[$file]) or \unlink($file);
        }
    }

    /**
     * @return \Generator<string, array{int, list<list<Span>>}> Store span limit and frames.
     */
    private static function scenarios(): \Generator
    {
        $chain = static fn(int $count, string $trace = self::TRACE_A): array => \array_map(
            static fn(int $i): Span => self::span($trace, $i + 1, $i === 0 ? null : $i, $i * 10, 10_000 - $i * 10),
            \range(0, $count - 1),
        );
        $fanOut = static fn(int $count): array => \array_map(
            static fn(int $i): Span => self::span(self::TRACE_A, $i + 2, 1, 100 + ($i * 37) % 500, 200 + $i),
            \range(0, $count - 1),
        );
        $root = self::span(self::TRACE_A, 1, null, 0, 5_000);

        yield 'chain, leaf first, one span per frame' => [10_000, self::oneByOne(\array_reverse($chain(40)))];
        yield 'chain, root first, one span per frame' => [10_000, self::oneByOne($chain(40))];
        // Spans 200..1200 of a chain, then span 199 with more than 1000 earlier descendants.
        yield 'chain with 1000+ earlier descendants' => [10_000, [
            \array_slice($chain(1200), 199),
            [self::span(self::TRACE_A, 199, 198, 1, 2)],
        ]];
        yield 'fan-out, children first, one span per frame' => [10_000, [...self::oneByOne($fanOut(40)), [$root]]];
        yield 'fan-out, parent first, one span per frame' => [10_000, [[$root], ...self::oneByOne($fanOut(40))]];
        // A chain deeper than the indentation limit and more children than one frame prints.
        yield 'deep and wide frame, truncated' => [10_000, [[
            $root,
            ...\array_map(
                static fn(int $i): Span => self::span(self::TRACE_A, 5000 + $i, $i === 0 ? 1 : 4999 + $i, $i, 4000 - $i),
                \range(0, 39),
            ),
            ...$fanOut(600),
        ]]];
        yield 'parent after 520 earlier children' => [10_000, [$fanOut(520), [$root]]];

        $mid = self::span(self::TRACE_A, 2, 1, 50, 900);
        $leaves = \array_map(
            static fn(int $i): Span => self::span(self::TRACE_A, 100 + $i, 2, 60 + $i, 80 + $i),
            \range(0, 29),
        );
        yield 'collapsed earlier descendants' => [10_000, [...self::oneByOne($leaves), [$mid], [$root]]];
        yield 'exactly 16 and 17 earlier descendants' => [10_000, [
            \array_slice($leaves, 0, 16),
            [$mid],
            [self::span(self::TRACE_B, 2, 1, 50, 900)],
            \array_map(static fn(int $i): Span => self::span(self::TRACE_B, 100 + $i, 2, 60, 70), \range(0, 16)),
            [self::span(self::TRACE_B, 1, null, 0, 1000)],
        ]];
        yield 'ancestors above' => [10_000, [
            $chain(5),
            [self::span(self::TRACE_A, 6, 5, 60, 70)],
            [self::span(self::TRACE_B, 1, null, 0, 100)],
            [self::span(self::TRACE_B, 2, 1, 10, 90)],
            [self::span(self::TRACE_B, 3, 2, 20, 80)],
            \array_map(static fn(int $i): Span => self::span(self::TRACE_C, $i + 1, $i ?: null, $i, 2000 - $i), \range(0, 1100)),
            [self::span(self::TRACE_C, 1200, 1101, 5000, 5001)],
        ]];
        yield 'orphans and a late parent' => [10_000, [
            [self::span(self::TRACE_A, 3, 2, 10, 20), self::span(self::TRACE_A, 4, 2, 15, 25)],
            [self::span(self::TRACE_A, 5, 9, 30, 40)],
            [self::span(self::TRACE_A, 6, 3, 11, 12)],
            [self::span(self::TRACE_A, 2, 1, 5, 50)],
            [self::span(self::TRACE_A, 7, 8, 60, 70), self::span(self::TRACE_A, 8, 1, 55, 80)],
        ]];
        yield 'cycles and self parents' => [10_000, [
            [self::span(self::TRACE_A, 1, 2, 0, 10), self::span(self::TRACE_A, 2, 1, 5, 15)],
            [self::span(self::TRACE_A, 3, 3, 1, 2)],
            [self::span(self::TRACE_A, 4, 5, 3, 4)],
            [self::span(self::TRACE_A, 5, 6, 3, 4)],
            [self::span(self::TRACE_A, 6, 4, 3, 4), self::span(self::TRACE_A, 7, 6, 3, 4)],
            [self::span(self::TRACE_A, 8, 7, 1, 1)],
        ]];
        // Spans with details, so that detail lines stay covered.
        $detailed = static fn(string $trace, int $id, ?int $parent, int $start, int $end, ?string $name = null): Span
            => self::span($trace, $id, $parent, $start, $end, $name, ['n' => $id]);
        yield 'several traces per frame' => [10_000, [
            [
                $detailed(self::TRACE_B, 2, 1, 10, 20),
                $detailed(self::TRACE_A, 2, 1, 10, 20),
                $detailed(self::TRACE_C, 1, null, 0, 30),
                $detailed(self::TRACE_A, 3, 2, 12, 18),
            ],
            [$detailed(self::TRACE_A, 1, null, 0, 40), $detailed(self::TRACE_B, 1, null, 0, 40)],
            [$detailed(self::TRACE_C, 2, 1, 1, 2), $detailed(self::TRACE_B, 3, 2, 11, 12)],
        ]];
        yield 'span sent again with other fields' => [10_000, [
            [$detailed(self::TRACE_A, 2, 1, 10, 20), $detailed(self::TRACE_A, 3, 1, 30, 40)],
            [$detailed(self::TRACE_A, 2, 3, 35, 36, 'moved')],
            [$detailed(self::TRACE_A, 4, 2, 1, 99_999), $detailed(self::TRACE_A, 4, 3, 31, 32, 'moved')],
            [$detailed(self::TRACE_A, 3, 1, -5, 40), $detailed(self::TRACE_A, 1, null, 0, 50)],
            [$detailed(self::TRACE_A, 1, null, 0, 45, 'root again')],
        ]];
        yield 'all-digit IDs' => [10_000, [
            [new Span('12345678901234567890123456789012', '1234567890123456', '2234567890123456', 'child', SpanKind::Client, 5, 6)],
            [new Span('12345678901234567890123456789012', '2234567890123456', null, 'root', SpanKind::Server, 1, 9)],
        ]];
        yield 'extreme and reversed times' => [10_000, [
            [self::span(self::TRACE_A, 1, null, 0, \PHP_INT_MAX)],
            [self::span(self::TRACE_A, 2, 1, \PHP_INT_MAX, 5), self::span(self::TRACE_A, 3, 1, 0, 0)],
        ]];
        yield 'trimmed long trace' => [20, self::oneByOne(\array_reverse($chain(50)))];
        yield 'frame larger than the store' => [20, [$chain(40), [self::span(self::TRACE_A, 41, 40, 1, 2)]]];
        yield 'evicted traces' => [30, self::interleave(
            $chain(12, self::TRACE_A),
            $chain(12, self::TRACE_B),
            $chain(12, self::TRACE_C),
            \array_reverse($chain(12, self::TRACE_A)),
        )];
        yield 'random trees' => [10_000, self::randomFrames(42, 400)];
        yield 'random trees in a small store' => [150, self::randomFrames(7, 400)];
        yield 'random trees, one span per frame' => [10_000, self::oneByOne(\array_merge(...self::randomFrames(3, 300)))];
    }

    private static function snapshotFile(string $scenario): string
    {
        return self::SNAPSHOTS . '/'
            . \trim((string) \preg_replace('/[^a-z0-9]+/', '-', \strtolower($scenario)), '-') . '.txt';
    }

    /**
     * @param list<Span> $spans
     * @return list<list<Span>>
     */
    private static function oneByOne(array $spans): array
    {
        return \array_map(static fn(Span $span): array => [$span], $spans);
    }

    /**
     * Frames that take one span of each list in turn.
     *
     * @param list<Span> ...$lists
     * @return list<list<Span>>
     */
    private static function interleave(array ...$lists): array
    {
        $frames = [];
        for ($i = 0; $i < \max(\array_map('count', $lists)); ++$i) {
            foreach ($lists as $list) {
                isset($list[$i]) and $frames[] = [$list[$i]];
            }
        }

        return $frames;
    }

    /**
     * Spans of three traces with random parents, a missing parent or itself among them,
     * shuffled into frames of 1 to 20 spans.
     *
     * @return list<list<Span>>
     */
    private static function randomFrames(int $seed, int $count): array
    {
        \mt_srand($seed);
        $spans = [];
        for ($i = 1; $i <= $count; ++$i) {
            $trace = [self::TRACE_A, self::TRACE_B, self::TRACE_C][\mt_rand(0, 2)];
            $parent = match (\mt_rand(0, 9)) {
                0 => null,
                1 => $count + \mt_rand(1, 5),
                2 => $i,
                default => \mt_rand(1, \max(1, $i - 1)),
            };
            $start = \mt_rand(0, 1000);
            $spans[] = self::span($trace, $i, $parent, $start, $start + \mt_rand(0, 300));
            // Some spans are sent twice.
            \mt_rand(0, 19) === 0 and $spans[] = self::span($trace, \mt_rand(1, $i), $parent, $start, $start + 1, 'again');
        }

        // Shuffle with mt_rand, so that the order depends only on the seed.
        for ($i = \count($spans) - 1; $i > 0; --$i) {
            $j = \mt_rand(0, $i);
            [$spans[$i], $spans[$j]] = [$spans[$j], $spans[$i]];
        }

        $frames = [];
        while ($spans !== []) {
            $frames[] = \array_splice($spans, 0, \mt_rand(1, 20));
        }
        \mt_srand();

        return $frames;
    }

    private static function span(
        string $trace,
        int $id,
        ?int $parent,
        int $start,
        int $end,
        ?string $name = null,
        array $attributes = [],
    ): Span {
        return new Span(
            traceId: $trace,
            spanId: \sprintf('%016x', $id),
            parentSpanId: $parent === null ? null : \sprintf('%016x', $parent),
            name: $name ?? "span-$id",
            kind: SpanKind::Internal,
            startTimeUnixNano: $start,
            endTimeUnixNano: $end,
            attributes: $attributes,
        );
    }
}
