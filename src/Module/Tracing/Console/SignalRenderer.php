<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Tracing\Console;

use Buggregator\Trap\Module\Console\Renderer as RendererInterface;
use Buggregator\Trap\Module\Console\Support\Common;
use Buggregator\Trap\Module\Tracing\Frame\OtlpLogs;
use Buggregator\Trap\Module\Tracing\Frame\OtlpMetrics;
use Buggregator\Trap\Server\Proto\Frame;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Renders received OTLP logs and metrics one line per record, at most {@see self::MAX_LINES} lines a frame.
 *
 * @implements RendererInterface<OtlpLogs|OtlpMetrics>
 *
 * @internal
 */
final class SignalRenderer implements RendererInterface
{
    private const MAX_LINES = 50;

    #[\Override]
    public function isSupport(Frame $frame): bool
    {
        return $frame instanceof OtlpLogs || $frame instanceof OtlpMetrics;
    }

    #[\Override]
    public function render(OutputInterface $output, Frame $frame): void
    {
        \assert($frame instanceof OtlpLogs || $frame instanceof OtlpMetrics);

        $lines = [];
        $services = [];
        if ($frame instanceof OtlpLogs) {
            $count = $frame->countRecords();
            Common::renderHeader1($output, 'OTLP LOGS', ...['gray' => $count === 1 ? '1 record' : "$count records"]);
            foreach ($frame->resourceLogs as $resource) {
                $services[] = $resource['resource']['attributes']['service.name'] ?? null;
                foreach ($resource['logRecords'] as $record) {
                    $body = $record['body'];
                    $level = $record['severityText'] !== '' ? $record['severityText'] : (string) $record['severityNumber'];
                    $lines[] = \sprintf(
                        '<fg=gray>%s</> %s',
                        self::escape($level),
                        self::escape(\is_string($body) ? $body : (string) \json_encode($body)),
                    );
                }
            }
        } else {
            $count = $frame->countDataPoints();
            Common::renderHeader1($output, 'OTLP METRICS', ...['gray' => $count === 1 ? '1 data point' : "$count data points"]);
            foreach ($frame->resourceMetrics as $resource) {
                $services[] = $resource['resource']['attributes']['service.name'] ?? null;
                foreach ($resource['metrics'] as $metric) {
                    foreach ($metric['dataPoints'] as $point) {
                        $lines[] = \sprintf(
                            '%s <fg=gray>%s</> %s %s',
                            self::escape($metric['name']),
                            $metric['type'],
                            \rtrim(\rtrim(\sprintf('%.6F', $point['value']), '0'), '.'),
                            self::escape($metric['unit']),
                        );
                    }
                }
            }
        }

        Common::renderMetadata($output, \array_filter([
            'Time' => $frame->time,
            'Service' => \implode(', ', \array_unique(\array_filter($services, \is_string(...)))),
        ], static fn(mixed $value): bool => $value !== ''));

        foreach (\array_slice($lines, 0, self::MAX_LINES) as $line) {
            $output->writeln($line);
        }
        \count($lines) > self::MAX_LINES
            and $output->writeln(\sprintf('<fg=gray>... %d more</>', \count($lines) - self::MAX_LINES));
    }

    private static function escape(string $text): string
    {
        return OutputFormatter::escape((string) \preg_replace('/[\x00-\x1F\x7F]/', '?', $text));
    }
}
