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

        return isset($report['message']) ? "$type: {$report['message']}" : $type;
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

        Common::renderHeader1($output, 'CSP', $frame->format, ...($frame->project === null ? [] : ['cyan' => $frame->project]));
        Common::renderMetadata($output, ['Time' => $frame->time]);

        foreach ($frame->reports as $report) {
            self::renderReport($output, $report);
        }
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
            'Policy' => $report['policy'] ?? '',
            'User agent' => $report['user_agent'] ?? '',
        ];
        Common::renderMetadata($output, \array_map(
            OutputFormatter::escape(...),
            \array_filter($metadata, static fn(string $value): bool => $value !== ''),
        ));
    }
}
