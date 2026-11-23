<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Csp\Console;

use Buggregator\Trap\Module\Console\Renderer as RendererInterface;
use Buggregator\Trap\Module\Console\Support\Common;
use Buggregator\Trap\Module\Csp\Frame as CspFrame;
use Buggregator\Trap\Module\Csp\Report;
use Buggregator\Trap\Server\Proto\Frame;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * A block per report of a request: what was blocked or reported, on which page, by which directive and where.
 *
 * @implements RendererInterface<CspFrame>
 *
 * @psalm-import-type CspReport from Report
 *
 * @internal
 */
final class Renderer implements RendererInterface
{
    /**
     * A one-line title of a report: `script-src-elem blocked https://cdn.test/x.js`, or `deprecation: <message>`.
     *
     * @param CspReport $report
     */
    public static function title(array $report): string
    {
        if (Report::isViolation($report)) {
            $directive = $report['directive'] ?? 'CSP';
            $blocked = $report['blocked'] ?? '';

            return $blocked === '' ? "$directive violation" : "$directive blocked $blocked";
        }

        $type = $report['type'] === '' ? 'report' : $report['type'];
        $facts = self::bodyFacts($report);
        $detail = $report['message'] ?? $facts['Error'] ?? $facts['Reason'] ?? '';

        return $detail === '' ? $type : "$type: $detail";
    }

    #[\Override]
    public function isSupport(Frame $frame): bool
    {
        return $frame instanceof CspFrame;
    }

    #[\Override]
    public function render(OutputInterface $output, Frame $frame): void
    {
        \assert($frame instanceof CspFrame);

        Common::renderHeader1(
            $output,
            'CSP',
            $frame->format,
            ...($frame->project === null ? [] : ['cyan' => OutputFormatter::escape($frame->project)]),
        );
        Common::renderMetadata($output, ['Time' => $frame->time]);

        foreach ($frame->reports as $report) {
            self::renderReport($output, $report);
        }
    }

    /**
     * What the body of a report of a type without fields of its own tells: the failure of a `network-error`
     * report of Network Error Logging, the reason of a `crash`.
     *
     * @param CspReport $report
     *
     * @return array<non-empty-string, non-empty-string> Label => value.
     */
    private static function bodyFacts(array $report): array
    {
        $body = (array) $report['body'];
        $text = static function (string $key) use ($body): string {
            /** @var mixed $value */
            $value = $body[$key] ?? null;

            return \is_string($value) || \is_int($value) || \is_float($value) ? (string) $value : '';
        };

        $facts = match ($report['type']) {
            'network-error' => [
                'Error' => $text('type'),
                'Phase' => $text('phase'),
                'Status' => $text('status_code') === '0' ? '' : $text('status_code'),
                'Elapsed' => $text('elapsed_time') === '' ? '' : $text('elapsed_time') . ' ms',
                'Server IP' => $text('server_ip'),
            ],
            'crash' => ['Reason' => $text('reason')],
            default => [],
        };

        return \array_filter($facts, static fn(string $value): bool => $value !== '');
    }

    /**
     * @param CspReport $report
     */
    private static function renderReport(OutputInterface $output, array $report): void
    {
        Common::renderHeader2($output, OutputFormatter::escape(self::title($report)));

        $disposition = $report['disposition'] ?? '';
        $metadata = [
            'Type' => $report['type'],
            'Page' => $report['url'],
            'Directive' => $report['directive'] ?? '',
            'Blocked' => $report['blocked'] ?? '',
            'Disposition' => match ($disposition) {
                'enforce' => 'enforce (blocked)',
                'report' => 'report (Report-Only: not blocked)',
                default => $disposition,
            },
            'Source' => Report::source($report),
            'Sample' => $report['sample'] ?? '',
            'Message' => $report['message'] ?? '',
            'ID' => $report['id'] ?? '',
            ...self::bodyFacts($report),
            'Policy' => $report['policy'] ?? '',
            'User agent' => $report['user_agent'] ?? '',
        ];
        Common::renderMetadata($output, \array_map(
            OutputFormatter::escape(...),
            \array_filter($metadata, static fn(string $value): bool => $value !== ''),
        ));
    }
}
