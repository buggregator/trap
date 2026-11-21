<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Tracing\Console;

use Buggregator\Trap\Module\Tracing\Console\SignalRenderer;
use Buggregator\Trap\Module\Tracing\Frame\OtlpTraces;
use Buggregator\Trap\Module\Tracing\Http\OtlpTrap\JsonLogsDecoder;
use Buggregator\Trap\Module\Tracing\Http\OtlpTrap\JsonMetricsDecoder;
use Symfony\Component\Console\Output\BufferedOutput;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Covers(SignalRenderer::class)]
#[Test]
final class SignalRendererTest
{
    public function testRendersLogRecords(): void
    {
        $output = new BufferedOutput();
        $frame = (new JsonLogsDecoder())->decode(
            '{"resourceLogs":[{"resource":{"attributes":[{"key":"service.name","value":{"stringValue":"billing"}}]},'
            . '"scopeLogs":[{"logRecords":[{"severityText":"ERROR","body":{"stringValue":"Payment <b>failed"}},'
            . '{"severityNumber":9,"body":{"intValue":"5"}}]}]}]}',
            new \DateTimeImmutable(),
        );

        (new SignalRenderer())->render($output, $frame);

        Assert::string($output->fetch())
            ->contains('OTLP LOGS')
            ->contains('2 records')
            ->contains('Service: billing')
            ->contains('ERROR Payment <b>failed')
            ->contains('9 5');
    }

    public function testRendersMetricValues(): void
    {
        $output = new BufferedOutput();
        $frame = (new JsonMetricsDecoder())->decode(
            '{"resourceMetrics":[{"scopeMetrics":[{"metrics":[{"name":"latency","unit":"ms",'
            . '"gauge":{"dataPoints":[{"asDouble":12.5}]}}]}]}]}',
            new \DateTimeImmutable(),
        );

        (new SignalRenderer())->render($output, $frame);

        Assert::string($output->fetch())->contains('1 data point')->contains('latency gauge 12.5 ms');
    }

    public function testSupportsLogsAndMetricsOnly(): void
    {
        $renderer = new SignalRenderer();

        Assert::false($renderer->isSupport(new OtlpTraces([])));
        Assert::true($renderer->isSupport((new JsonLogsDecoder())->decode('{}', new \DateTimeImmutable())));
    }
}
