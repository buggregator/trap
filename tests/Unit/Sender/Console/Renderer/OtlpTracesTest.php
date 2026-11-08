<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Sender\Console\Renderer;

use Buggregator\Trap\Handler\Http\Middleware\OtlpTrap\JsonTracesDecoder;
use Buggregator\Trap\Info;
use Buggregator\Trap\Module\Tracing\SpanStore;
use Buggregator\Trap\Proto\Frame\Monolog;
use Buggregator\Trap\Proto\Frame\Otlp\OtlpTraces as OtlpTracesFrame;
use Buggregator\Trap\Proto\Frame\Otlp\Trace\ResourceSpans;
use Buggregator\Trap\Proto\Frame\Otlp\Trace\ScopeSpans;
use Buggregator\Trap\Proto\Frame\Otlp\Trace\Span;
use Buggregator\Trap\Proto\Frame\Otlp\Trace\SpanKind;
use Buggregator\Trap\Sender\Console\Renderer\OtlpTraces;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Covers(OtlpTraces::class)]
#[Test]
final class OtlpTracesTest
{
    public function testSupportsOnlyOtlpTraces(): void
    {
        $renderer = new OtlpTraces();

        Assert::true($renderer->isSupport(new OtlpTracesFrame([])));
        Assert::false($renderer->isSupport(new Monolog([])));
    }

    public function testRendersTraceAsTree(): void
    {
        $output = $this->render($this->fixtureFrame());

        Assert::string($output)->contains('OTLP TRACES  4 spans')->contains('Service: e2e-app')->contains('Scope: e2e.scope 1.2.3')->contains('# Trace 382bfca057473920912cab0a4ed95abe  27.69 ms')->notContains('host.name', 'Resource attributes are verbose-only.');

        $lines = $this->spanLines($output);
        Assert::same($lines, [
            'GET /orders SERVER 27.69 ms OK id:286e73f2056fe20e',
            '├─ db.query CLIENT 11.82 ms ERROR: Connection lost +832.10 µs id:e0d59f245a195412',
            '└─ render INTERNAL 14.68 ms +12.90 ms id:658f956912e8b228',
            '   └─ template.load INTERNAL 14.46 ms +12.97 ms id:8118bc4ed687fb8d',
        ]);

        Assert::string($output)->contains("│  http.request.method: \"GET\"\n")->contains("│  order.ids: [1,2,3]\n")->contains("│     db.system: \"mysql\"\n")->contains('│     • exception +10.92 ms exception.type="RuntimeException" exception.message="Connection lost"')->contains("         template.name: \"orders.twig\"\n");
    }

    public function testRendersResourceAttributesInVerboseMode(): void
    {
        $output = $this->render($this->fixtureFrame(), OutputInterface::VERBOSITY_VERBOSE);

        Assert::string($output)->contains('host.name: example-host')->contains('telemetry.sdk.language: php');
    }

    public function testEscapesMarkupAndControlCharactersOfReceivedText(): void
    {
        $span = new Span(
            traceId: '5b8efff798038103d269b633813fc60c',
            spanId: 'eee19b7ec3c1b174',
            parentSpanId: null,
            name: "<fg=foo>span</>\e[31m",
            kind: SpanKind::Internal,
            startTimeUnixNano: 1,
            endTimeUnixNano: 2,
            attributes: ['<fg=bar>key</>' => "red\e[31m"],
        );
        $frame = new OtlpTracesFrame([new ResourceSpans(
            [new ScopeSpans([$span], scopeName: '<fg=foo>scope</>', scopeVersion: '<bg=foo>1</>')],
            resourceAttributes: [
                'service.name' => "<fg=foo>svc</>\e]0;title\x07",
                '<fg=foo>k</>' => "<fg=foo>v</>\u{9B}31m",
            ],
        )]);

        $output = $this->render($frame, OutputInterface::VERBOSITY_VERBOSE);

        Assert::string($output)->contains('Service: <fg=foo>svc</>\x1B]0;title\x07')->contains('Scope: <fg=foo>scope</> <bg=foo>1</>')->contains('<fg=foo>k</>: <fg=foo>v</>\u{009B}31m')->contains('<fg=foo>span</>\x1B[31m')->contains('<fg=bar>key</>: "red\u001b[31m"')->notContains("\e");
    }

    public function testRendersSpanWithMissingParentAsRoot(): void
    {
        $frame = new OtlpTracesFrame([new ResourceSpans([new ScopeSpans([
            new Span(
                traceId: '5b8efff798038103d269b633813fc60c',
                spanId: 'eee19b7ec3c1b174',
                parentSpanId: '0102030405060708',
                name: 'child <of> nothing',
                kind: SpanKind::Producer,
                startTimeUnixNano: 1_000,
                endTimeUnixNano: 1_500,
            ),
        ])])]);

        $output = $this->render($frame);

        Assert::string($output)->contains('OTLP TRACES  1 span');
        Assert::same($this->spanLines($output), ['child <of> nothing PRODUCER 500 ns id:eee19b7ec3c1b174 parent:0102030405060708']);
    }

    public function testGroupsSpansByTrace(): void
    {
        $span = static fn(string $traceId, string $name): Span => new Span(
            traceId: $traceId,
            spanId: \substr(\md5($name), 0, 16),
            parentSpanId: null,
            name: $name,
            kind: SpanKind::Internal,
            startTimeUnixNano: 0,
            endTimeUnixNano: 2_500_000_000,
        );
        $frame = new OtlpTracesFrame([new ResourceSpans([new ScopeSpans([
            $span(\str_repeat('a', 32), 'first'),
            $span(\str_repeat('b', 32), 'second'),
            $span(\str_repeat('a', 32), 'third'),
        ])])]);

        $output = $this->render($frame);

        Assert::same(\substr_count($output, '# Trace '), 2);
        Assert::string($output)->matchesRegex('/# Trace a{32}  2\.50 s\s+first .*\s+third /')->matchesRegex('/# Trace b{32}  2\.50 s\s+second /');
    }

    public function testBuildsTreeFromSpansReceivedOneByOne(): void
    {
        $renderer = new OtlpTraces(new SpanStore());
        $root = self::span('1', null, 'root', 0, 100);
        $child = self::span('2', '1', 'child', 10, 50);
        $grandchild = self::span('3', '2', 'grandchild', 20, 30);

        $first = $this->render(self::frame($grandchild), renderer: $renderer);
        $second = $this->render(self::frame($child), renderer: $renderer);
        $third = $this->render(self::frame($root), renderer: $renderer);

        Assert::same($this->spanLines($first), ['grandchild INTERNAL 10 ns id:3333333333333333 parent:2222222222222222']);
        Assert::string($second)->contains('+1 received earlier');
        Assert::same($this->spanLines($second), [
            'child INTERNAL 40 ns id:2222222222222222 parent:1111111111111111',
            '└─ grandchild INTERNAL 10 ns +10 ns id:3333333333333333',
        ]);
        Assert::string($third)->contains('+2 received earlier');
        Assert::same($this->spanLines($third), [
            'root INTERNAL 100 ns id:1111111111111111',
            '└─ child INTERNAL 40 ns +10 ns id:2222222222222222',
            '   └─ grandchild INTERNAL 10 ns +20 ns id:3333333333333333',
        ]);
        Assert::string($third)->contains('         attr: "grandchild"', 'Earlier spans keep their details.');
    }

    public function testShowsAncestorsReceivedEarlierAsContext(): void
    {
        $renderer = new OtlpTraces(new SpanStore());
        $renderer->render(new BufferedOutput(), self::frame(
            self::span('1', null, 'root', 0, 100),
            self::span('2', '1', 'parent', 10, 50),
            self::span('4', '1', 'sibling', 60, 70),
        ));

        $output = $this->render(self::frame(self::span('3', '2', 'late child', 20, 90)), renderer: $renderer);

        Assert::same($this->spanLines($output), [
            'parent INTERNAL 40 ns id:2222222222222222 (1 more ancestor above)',
            '└─ late child INTERNAL 70 ns +20 ns id:3333333333333333',
        ]);
        Assert::string($output)->notContains('attr: "parent"', 'Context spans have no details.')->contains('      attr: "late child"')->notContains('sibling')->notContains('root');
    }

    public function testSingleSpanNotShownIsSingular(): void
    {
        $spans = [];
        for ($i = 0; $i < 501; ++$i) {
            $spans[] = self::chainSpan($i);
        }

        $output = $this->render(self::frame(...$spans));

        Assert::string($output)->contains("… 1 more span not shown\n");
    }

    public function testDeepChainInOneFrameIsBounded(): void
    {
        $spans = [];
        for ($i = 0; $i < 600; ++$i) {
            $spans[] = self::chainSpan($i);
        }

        $output = $this->render(self::frame(...$spans));
        $lines = $this->spanLines($output);

        Assert::count($lines, 500);
        Assert::string($output)->contains('… 100 more spans not shown');
        Assert::numeric(\max(\array_map('strlen', \explode("\n", $output))))->lessThan(200, 'Indentation stops growing.');
        Assert::string($output)->contains('[depth 30]');
    }

    public function testChainReceivedLeafFirstPrintsLinearOutput(): void
    {
        $renderer = new OtlpTraces(new SpanStore());
        $total = 0;
        for ($i = 99; $i >= 0; --$i) {
            $output = $this->render(self::frame(self::chainSpan($i)), renderer: $renderer);
            $total += \count($this->spanLines($output));
        }

        Assert::count($this->spanLines($output), 100, 'The root brings the complete tree.');
        // Each of the 99 earlier frames prints its span with at most 16 earlier descendants.
        Assert::numeric($total)->lessThanOrEqual(99 * 17 + 100);
    }

    public function testCollapsesManyEarlierDescendants(): void
    {
        $renderer = new OtlpTraces(new SpanStore());
        for ($i = 99; $i >= 1; --$i) {
            $renderer->render(new BufferedOutput(), self::frame(self::chainSpan($i)));
        }

        $output = $this->render(self::frame(self::chainSpan(1)), renderer: $renderer);

        Assert::count($this->spanLines($output), 1);
        Assert::string($output)->contains('… 98 earlier descendants');
    }

    public function testChainReceivedRootFirstShowsOnlyNearestAncestor(): void
    {
        $renderer = new OtlpTraces(new SpanStore());
        for ($i = 0; $i < 99; ++$i) {
            $renderer->render(new BufferedOutput(), self::frame(self::chainSpan($i)));
        }

        $output = $this->render(self::frame(self::chainSpan(99)), renderer: $renderer);

        Assert::count($this->spanLines($output), 2);
        Assert::string($output)->contains('(98 more ancestors above)');
    }

    public function testRendererWithoutSharedStoreKeepsItsOwnSpans(): void
    {
        $first = new OtlpTraces();
        $second = new OtlpTraces();
        $first->render(new BufferedOutput(), self::frame(self::span('1', null, 'root', 0, 100)));

        $output = $this->render(self::frame(self::span('2', '1', 'child', 10, 50)), renderer: $second);

        Assert::same($this->spanLines($output), ['child INTERNAL 40 ns id:2222222222222222 parent:1111111111111111']);
    }

    public function testRendersSpansOfParentCycle(): void
    {
        $output = $this->render(self::frame(
            self::span('1', '2', 'first', 0, 10),
            self::span('2', '1', 'second', 0, 10),
        ));

        Assert::count($this->spanLines($output), 2);
    }

    public function testRendersAllDigitIds(): void
    {
        $span = new Span(
            traceId: \str_repeat('1', 32),
            spanId: '1234567890123456',
            parentSpanId: null,
            name: 'digits',
            kind: SpanKind::Internal,
            startTimeUnixNano: 0,
            endTimeUnixNano: 1,
        );

        $output = $this->render(self::frame($span));

        Assert::string($output)->contains('# Trace ' . \str_repeat('1', 32));
        Assert::same($this->spanLines($output), ['digits INTERNAL 1 ns id:1234567890123456']);
    }

    public function testRendersExtremeTimestamps(): void
    {
        $renderer = new OtlpTraces(new SpanStore());
        $renderer->render(new BufferedOutput(), self::frame(self::span('1', null, 'root', 0, \PHP_INT_MAX)));

        $output = $this->render(
            self::frame(
                self::span('2', '1', 'reversed', \PHP_INT_MAX, 5),
                self::span('3', '1', 'min', \PHP_INT_MIN, \PHP_INT_MAX),
            ),
            renderer: $renderer,
        );

        Assert::string($output)->contains('reversed INTERNAL 0 ns')->contains('9223372036.85 s');
    }

    /**
     * A span of a chain where span N is the child of span N-1.
     */
    private static function chainSpan(int $index): Span
    {
        return new Span(
            traceId: \str_repeat('c', 32),
            spanId: \sprintf('%016x', $index + 1),
            parentSpanId: $index === 0 ? null : \sprintf('%016x', $index),
            name: "span-$index",
            kind: SpanKind::Internal,
            startTimeUnixNano: $index,
            endTimeUnixNano: 1000 - $index,
        );
    }

    private static function frame(Span ...$spans): OtlpTracesFrame
    {
        return new OtlpTracesFrame([new ResourceSpans([new ScopeSpans(\array_values($spans))])]);
    }

    private static function span(string $id, ?string $parent, string $name, int $start, int $end): Span
    {
        return new Span(
            traceId: \str_repeat('a', 32),
            spanId: \str_repeat($id, 16),
            parentSpanId: $parent === null ? null : \str_repeat($parent, 16),
            name: $name,
            kind: SpanKind::Internal,
            startTimeUnixNano: $start,
            endTimeUnixNano: $end,
            attributes: ['attr' => $name],
        );
    }

    private function render(
        OtlpTracesFrame $frame,
        int $verbosity = OutputInterface::VERBOSITY_NORMAL,
        OtlpTraces $renderer = new OtlpTraces(),
    ): string {
        $output = new BufferedOutput($verbosity);
        $renderer->render($output, $frame);

        return \str_replace("\r\n", "\n", $output->fetch());
    }

    /**
     * @return list<string> Lines with a span header: the ones carrying a span ID.
     */
    private function spanLines(string $output): array
    {
        return \array_values(\array_filter(
            \explode("\n", $output),
            static fn(string $line): bool => \str_contains($line, ' id:'),
        ));
    }

    private function fixtureFrame(): OtlpTracesFrame
    {
        return (new JsonTracesDecoder())->decode(
            \file_get_contents(Info::TRAP_ROOT . '/tests/Stub/otlp-traces.json'),
            new \DateTimeImmutable('2024-01-01 12:00:00'),
        );
    }
}
