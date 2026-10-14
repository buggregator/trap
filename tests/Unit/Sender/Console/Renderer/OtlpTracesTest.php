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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

#[CoversClass(OtlpTraces::class)]
final class OtlpTracesTest extends TestCase
{
    public function testSupportsOnlyOtlpTraces(): void
    {
        $renderer = new OtlpTraces();

        self::assertTrue($renderer->isSupport(new OtlpTracesFrame([])));
        self::assertFalse($renderer->isSupport(new Monolog([])));
    }

    public function testRendersTraceAsTree(): void
    {
        $output = $this->render($this->fixtureFrame());

        self::assertStringContainsString('OTLP TRACES  4 spans', $output);
        self::assertStringContainsString('Service: e2e-app', $output);
        self::assertStringContainsString('Scope: e2e.scope 1.2.3', $output);
        self::assertStringContainsString('# Trace 382bfca057473920912cab0a4ed95abe  27.69 ms', $output);
        self::assertStringNotContainsString('host.name', $output, 'Resource attributes are verbose-only.');

        $lines = $this->spanLines($output);
        self::assertSame([
            'GET /orders SERVER 27.69 ms OK id:286e73f2056fe20e',
            '├─ db.query CLIENT 11.82 ms ERROR: Connection lost +832.10 µs id:e0d59f245a195412',
            '└─ render INTERNAL 14.68 ms +12.90 ms id:658f956912e8b228',
            '   └─ template.load INTERNAL 14.46 ms +12.97 ms id:8118bc4ed687fb8d',
        ], $lines);

        self::assertStringContainsString("│  http.request.method: \"GET\"\n", $output);
        self::assertStringContainsString("│  order.ids: [1,2,3]\n", $output);
        self::assertStringContainsString("│     db.system: \"mysql\"\n", $output);
        self::assertStringContainsString(
            '│     • exception +10.92 ms exception.type="RuntimeException" exception.message="Connection lost"',
            $output,
        );
        self::assertStringContainsString("         template.name: \"orders.twig\"\n", $output);
    }

    public function testRendersResourceAttributesInVerboseMode(): void
    {
        $output = $this->render($this->fixtureFrame(), OutputInterface::VERBOSITY_VERBOSE);

        self::assertStringContainsString('host.name: example-host', $output);
        self::assertStringContainsString('telemetry.sdk.language: php', $output);
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

        self::assertStringContainsString('OTLP TRACES  1 span', $output);
        self::assertSame(
            ['child <of> nothing PRODUCER 500 ns id:eee19b7ec3c1b174 parent:0102030405060708'],
            $this->spanLines($output),
        );
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

        self::assertSame(2, \substr_count($output, '# Trace '));
        self::assertMatchesRegularExpression('/# Trace a{32}  2\.50 s\s+first .*\s+third /', $output);
        self::assertMatchesRegularExpression('/# Trace b{32}  2\.50 s\s+second /', $output);
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

        self::assertSame(['grandchild INTERNAL 10 ns id:3333333333333333 parent:2222222222222222'], $this->spanLines($first));
        self::assertStringContainsString('+1 received earlier', $second);
        self::assertSame([
            'child INTERNAL 40 ns id:2222222222222222 parent:1111111111111111',
            '└─ grandchild INTERNAL 10 ns +10 ns id:3333333333333333',
        ], $this->spanLines($second));
        self::assertStringContainsString('+2 received earlier', $third);
        self::assertSame([
            'root INTERNAL 100 ns id:1111111111111111',
            '└─ child INTERNAL 40 ns +10 ns id:2222222222222222',
            '   └─ grandchild INTERNAL 10 ns +20 ns id:3333333333333333',
        ], $this->spanLines($third));
        self::assertStringContainsString('         attr: "grandchild"', $third, 'Earlier spans keep their details.');
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

        self::assertSame([
            'root INTERNAL 100 ns id:1111111111111111',
            '└─ parent INTERNAL 40 ns id:2222222222222222',
            '   └─ late child INTERNAL 70 ns +20 ns id:3333333333333333',
        ], $this->spanLines($output));
        self::assertStringNotContainsString('attr: "root"', $output, 'Context spans have no details.');
        self::assertStringContainsString('      attr: "late child"', $output);
        self::assertStringNotContainsString('sibling', $output);
    }

    public function testRendererWithoutSharedStoreKeepsItsOwnSpans(): void
    {
        $first = new OtlpTraces();
        $second = new OtlpTraces();
        $first->render(new BufferedOutput(), self::frame(self::span('1', null, 'root', 0, 100)));

        $output = $this->render(self::frame(self::span('2', '1', 'child', 10, 50)), renderer: $second);

        self::assertSame(['child INTERNAL 40 ns id:2222222222222222 parent:1111111111111111'], $this->spanLines($output));
    }

    public function testRendersSpansOfParentCycle(): void
    {
        $output = $this->render(self::frame(
            self::span('1', '2', 'first', 0, 10),
            self::span('2', '1', 'second', 0, 10),
        ));

        self::assertCount(2, $this->spanLines($output));
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

        self::assertStringContainsString('reversed INTERNAL 0 ns', $output);
        self::assertStringContainsString('9223372036.85 s', $output);
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
