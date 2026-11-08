<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Config\Server;

use Buggregator\Trap\Bootstrap;
use Buggregator\Trap\Config\Server\Traces;
use Buggregator\Trap\Info;
use Buggregator\Trap\Module\Tracing\SpanStore;
use Buggregator\Trap\Proto\Frame\Otlp\Trace\Span;
use Buggregator\Trap\Proto\Frame\Otlp\Trace\SpanKind;
use Internal\Container\Container;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Covers(Traces::class)]
#[Covers(SpanStore::class)]
#[Test]
final class TracesTest
{
    public static function provideInvalidEnvValues(): \Generator
    {
        yield 'zero' => ['0', '0'];
        yield 'negative' => ['-5', '-1.5'];
        yield 'not a number' => ['many', 'forever'];
    }

    public function testDefaults(): void
    {
        $config = $this->container()->get(Traces::class);

        Assert::same($config->maxSpans, 10_000);
        Assert::same($config->ttl, 600.0);
    }

    public function testTrapXmlHoldsDefaults(): void
    {
        $config = $this->container(xml: Info::TRAP_ROOT . '/trap.xml')->get(Traces::class);

        Assert::same($config->maxSpans, 10_000);
        Assert::same($config->ttl, 600.0);
    }

    public function testReadsXml(): void
    {
        $xml = '<?xml version="1.0"?><trap><traces maxSpans="50" ttl="12.5"/></trap>';

        $config = $this->container(xml: $xml)->get(Traces::class);

        Assert::same($config->maxSpans, 50);
        Assert::same($config->ttl, 12.5);
    }

    public function testEnvOverridesXml(): void
    {
        $xml = '<?xml version="1.0"?><trap><traces maxSpans="50" ttl="12.5"/></trap>';

        $config = $this->container(
            ['TRAP_TRACES_MAX_SPANS' => '2', 'TRAP_TRACES_TTL' => '30'],
            $xml,
        )->get(Traces::class);

        Assert::same($config->maxSpans, 2);
        Assert::same($config->ttl, 30.0);
    }

    public function testSpanStoreFromContainerUsesEnvLimits(): void
    {
        $store = $this->container(['TRAP_TRACES_MAX_SPANS' => '2'])->get(SpanStore::class);

        $store->add(self::span('a'));
        $store->add(self::span('b'));
        $store->add(self::span('c'));

        Assert::same($store->countSpans(), 2);
        Assert::same($store->getTrace(\str_repeat('a', 32)), []);
    }

    #[DataProvider('provideInvalidEnvValues')]
    public function testSpanStoreIgnoresInvalidEnvLimits(string $maxSpans, string $ttl): void
    {
        $store = $this->container(['TRAP_TRACES_MAX_SPANS' => $maxSpans, 'TRAP_TRACES_TTL' => $ttl])
            ->get(SpanStore::class);

        foreach (['a', 'b', 'c', 'd'] as $trace) {
            $store->add(self::span($trace));
        }

        Assert::same($store->countTraces(), 4);
    }

    private static function span(string $trace): Span
    {
        return new Span(
            traceId: \str_repeat($trace, 32),
            spanId: \str_repeat($trace, 16),
            parentSpanId: null,
            name: 'span',
            kind: SpanKind::Internal,
            startTimeUnixNano: 0,
            endTimeUnixNano: 1,
        );
    }

    /**
     * @param array<string, string> $env
     * @param non-empty-string|null $xml
     */
    private function container(array $env = [], ?string $xml = null): Container
    {
        return Bootstrap::init()->withConfig(xml: $xml, environment: $env)->finish();
    }
}
