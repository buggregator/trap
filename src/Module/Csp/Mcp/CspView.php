<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Csp\Mcp;

use Buggregator\Trap\Module\Csp\Console\Renderer;
use Buggregator\Trap\Module\Csp\Frontend\Mapper;
use Buggregator\Trap\Module\Csp\Report;
use Buggregator\Trap\Module\Mcp\View\EventFields;
use Buggregator\Trap\Module\Mcp\View\EventView;
use Buggregator\Trap\Module\Mcp\View\PayloadSummary;
use Buggregator\Trap\Server\Event\Event;
use Buggregator\Trap\Server\Event\Record;

/**
 * Security reports for the MCP tools: the summary and the fields; the text is the console one.
 *
 * @psalm-import-type CspReport from Report
 *
 * @internal
 */
final class CspView implements EventView, EventFields
{
    private const FIELDS = ['report_type', 'directive', 'blocked', 'disposition', 'page', 'source_file'];

    /**
     * The first report, its page, and how many more the request has.
     */
    #[\Override]
    public function summary(Event $event): ?string
    {
        $reports = self::reports($event);
        if ($reports === null || $reports === []) {
            return null;
        }

        $report = $reports[0];
        $summary = Renderer::title($report);
        $report['url'] === '' or $summary .= " on {$report['url']}";
        \count($reports) > 1 and $summary .= \sprintf(' (+%d more)', \count($reports) - 1);

        $summary = PayloadSummary::shorten($summary);

        return $summary === '' ? null : $summary;
    }

    #[\Override]
    public function text(Record $record): ?string
    {
        return null;
    }

    #[\Override]
    public function fieldNames(): array
    {
        return [Mapper::TYPE => self::FIELDS];
    }

    #[\Override]
    public function fields(Event $event): ?array
    {
        $reports = self::reports($event);
        if ($reports === null) {
            return null;
        }

        $values = static function (string $key) use ($reports): array {
            $values = [];
            foreach ($reports as $report) {
                /** @var mixed $value */
                $value = $report[$key] ?? null;
                \is_string($value) && $value !== '' && !\in_array($value, $values, true) and $values[] = $value;
            }

            return $values;
        };

        return [
            'report_type' => $values('type'),
            'directive' => $values('directive'),
            'blocked' => $values('blocked'),
            'disposition' => $values('disposition'),
            'page' => $values('url'),
            'source_file' => $values('source_file'),
        ];
    }

    /**
     * @return list<CspReport>|null Null for an event of another type.
     */
    private static function reports(Event $event): ?array
    {
        $payload = $event->payload;
        /** @var mixed $reports */
        $reports = \is_array($payload) ? $payload['reports'] ?? null : null;
        if ($event->type !== Mapper::TYPE || !\is_array($reports)) {
            return null;
        }

        return \array_values(\array_map(Report::restore(...), $reports));
    }
}
