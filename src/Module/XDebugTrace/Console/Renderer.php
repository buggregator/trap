<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\XDebugTrace\Console;

use Buggregator\Trap\Module\Console\Renderer as RendererInterface;
use Buggregator\Trap\Module\Console\Support\Common;
use Buggregator\Trap\Module\Console\Support\Tables;
use Buggregator\Trap\Module\XDebugTrace\Frame;
use Buggregator\Trap\Server\Proto\Frame as ProtoFrame;
use Buggregator\Trap\Support\Measure;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The summary of a trace: totals and the functions with the most self time. The call tree is for the UI and MCP.
 *
 * @implements RendererInterface<Frame>
 *
 * @internal
 */
final class Renderer implements RendererInterface
{
    private const TOP = 10;

    #[\Override]
    public function isSupport(ProtoFrame $frame): bool
    {
        return $frame instanceof Frame;
    }

    #[\Override]
    public function render(OutputInterface $output, ProtoFrame $frame): void
    {
        \assert($frame instanceof Frame);
        $summary = $frame->summary;

        Common::renderHeader1($output, 'XDEBUG TRACE', $summary['truncated'] ? 'cut' : null);
        Common::renderMetadata($output, \array_filter([
            'Time' => $frame->time,
            'Request' => $frame->url,
            'Name' => $frame->name,
            'Script' => $summary['main'],
            'Duration' => self::seconds($summary['duration']),
            'Peak memory' => Measure::memory(\max(0, $summary['peak_memory'])),
            'Calls' => \sprintf('%d (%d user-defined)', $summary['calls'], $summary['user_calls']),
            'Max depth' => (string) $summary['max_depth'],
            'Size' => Measure::memory($frame->size) . ($frame->compressed ? ', gzipped' : ''),
        ], static fn(mixed $value): bool => $value !== ''));

        $rows = [];
        foreach (\array_slice($summary['top'], 0, self::TOP) as $func) {
            $rows[] = [
                'Function' => $func['func'],
                'Calls' => (string) $func['count'],
                'Self' => self::seconds($func['self']),
                'Inclusive' => self::seconds($func['incl']),
            ];
        }
        Tables::renderMultiColumnTable($output, 'Most self time', $rows);
    }

    private static function seconds(float $seconds): string
    {
        return match (true) {
            $seconds < 0.001 => \sprintf('%.1f µs', $seconds * 1e6),
            $seconds < 1 => \sprintf('%.1f ms', $seconds * 1e3),
            default => \sprintf('%.2f s', $seconds),
        };
    }
}
