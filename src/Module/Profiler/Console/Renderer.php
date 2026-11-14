<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Profiler\Console;

use Buggregator\Trap\Module\Console\Renderer as RendererInterface;
use Buggregator\Trap\Module\Console\Support\Common;
use Buggregator\Trap\Module\Console\Support\Tables;
use Buggregator\Trap\Module\Profiler\Frame as ProfilerFrame;
use Buggregator\Trap\Module\Profiler\Frontend\Mapper;
use Buggregator\Trap\Module\Profiler\Frontend\Message\TopFunctions\Func;
use Buggregator\Trap\Module\Profiler\Struct\Cost;
use Buggregator\Trap\Module\Profiler\Struct\Edge;
use Buggregator\Trap\Module\Profiler\Struct\Peaks;
use Buggregator\Trap\Module\Profiler\Struct\Profile;
use Buggregator\Trap\Server\Proto\Frame;
use Buggregator\Trap\Support\Measure;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableStyle;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @implements RendererInterface<ProfilerFrame>
 *
 * @internal
 */
final class Renderer implements RendererInterface
{
    private const TOP_LIMIT = 10;
    private const TOP_LIMIT_VERBOSE = 30;
    private const NAME_WIDTH = 60;

    /**
     * Longer paths keep their start and their end: the end is where the time goes.
     */
    private const PATH_LIMIT = 12;

    private const PATH_HEAD = 3;

    /**
     * Metrics to rank the functions by, in the order of preference: the first one the profile measures is taken.
     */
    private const RANK_METRICS = ['wt', 'cpu', 'mu', 'ct'];

    #[\Override]
    public function isSupport(Frame $frame): bool
    {
        return $frame instanceof ProfilerFrame;
    }

    /**
     * @psalm-suppress MixedAssignment
     */
    #[\Override]
    public function render(OutputInterface $output, Frame $frame): void
    {
        \assert($frame instanceof ProfilerFrame);

        $subtitle = $frame->payload->type->value;
        Common::renderHeader1($output, 'PROFILER', $subtitle);

        $profile = $frame->payload->getProfile();
        $metadata = $profile->metadata;

        $data = [];
        isset($metadata['date']) && \is_numeric($metadata['date'])
        and $data['Time'] = new \DateTimeImmutable('@' . (string) $metadata['date']);
        \is_string($m = $metadata['app_name'] ?? null) and $data['App name'] = $m;
        \is_string($m = $metadata['hostname'] ?? null) and $data['Hostname'] = $m;
        \is_string($m = $metadata['filename'] ?? null) and $data['File name'] = $m . (
            \is_int($size = $metadata['filesize'] ?? null) && $size >= 0
                ? ' (' . Measure::memory($size) . ')'
                : ''
        );
        // What a profiler tells besides the costs, such as the request of an SPX report
        foreach (\is_array($m = $metadata['details'] ?? null) ? $m : [] as $label => $value) {
            \is_string($label) && \is_scalar($value) and $data[self::text($label)] = self::text((string) $value);
        }
        $data['Num edges'] = $profile->calls->count();

        Common::renderMetadata($output, $data);
        if ($profile->tags !== []) {
            $output->writeln('');
            Common::renderTags($output, $profile->tags);
            $output->writeln('');
        }

        $peaks = $profile->peaks;
        $countLabel = self::countLabel(Mapper::countType($frame->payload));
        Tables::renderKeyValueTable($output, 'Peak values', \array_filter([
            'Wall time' => $peaks->wt > 0 ? self::formatTime($peaks->wt) : null,
            'CPU time' => $peaks->cpu > 0 ? self::formatTime($peaks->cpu) : null,
            'Memory usage' => $peaks->mu > 0 ? Measure::memory($peaks->mu) : null,
            'Peak memory usage' => $peaks->pmu > 0 ? Measure::memory($peaks->pmu) : null,
            $countLabel => (string) $peaks->ct,
        ], static fn(?string $value): bool => $value !== null));

        $metric = self::rankMetric($peaks);
        if ($profile->calls->count() === 0 || $metric === null) {
            $output->writeln(['', '<fg=gray>No calls recorded.</>']);
            return;
        }

        $limit = $output->isVerbose() ? self::TOP_LIMIT_VERBOSE : self::TOP_LIMIT;
        $this->renderTopFunctions($output, $profile, $metric, $countLabel, $limit);
        $this->renderHotPath($output, $profile, $metric);
    }

    /**
     * @param list<Edge> $edges
     * @param 'wt'|'cpu'|'mu'|'ct' $metric
     */
    private static function heaviest(array $edges, string $metric): ?Edge
    {
        $result = null;
        foreach ($edges as $edge) {
            $value = self::metricOf($edge->cost, $metric);
            $value > 0 && ($result === null || $value > self::metricOf($result->cost, $metric))
                and $result = $edge;
        }

        return $result;
    }

    /**
     * @return 'wt'|'cpu'|'mu'|'ct'|null
     */
    private static function rankMetric(Peaks $peaks): ?string
    {
        foreach (self::RANK_METRICS as $metric) {
            if (self::metricOf($peaks->toCost(), $metric) > 0) {
                return $metric;
            }
        }

        return null;
    }

    /**
     * @param 'wt'|'cpu'|'mu'|'ct' $metric
     *
     * @return int<0, max>
     */
    private static function metricOf(Cost $cost, string $metric): int
    {
        return match ($metric) {
            'wt' => $cost->wt,
            'cpu' => $cost->cpu,
            'mu' => $cost->mu,
            'ct' => $cost->ct,
        };
    }

    /**
     * @param non-empty-string|null $countType See {@see Mapper::countType()}.
     */
    private static function countLabel(?string $countType): string
    {
        return match ($countType) {
            null => 'Calls',
            Mapper::SAMPLES => 'Samples',
            default => \ucfirst(self::text($countType)),
        };
    }

    private static function formatMetric(string $metric, int|float $value): string
    {
        $value = \max(0, (int) $value);
        return match ($metric) {
            'wt', 'cpu' => self::formatTime($value),
            'mu' => Measure::memory($value),
            default => (string) $value,
        };
    }

    /**
     * @param int<0, max> $microseconds
     */
    private static function formatTime(int $microseconds): string
    {
        return match (true) {
            $microseconds < 1_000 => "$microseconds µs",
            $microseconds < 1_000_000 => \sprintf('%.2f ms', (float) $microseconds / 1e3),
            default => \sprintf('%.2f s', (float) $microseconds / 1e6),
        };
    }

    private static function formatPercent(int|float $percent): string
    {
        return $percent > 0 && $percent < 0.05 ? '<0.1%' : \sprintf('%.1f%%', $percent);
    }

    /**
     * Pads by the display width: `sprintf()` counts the bytes of `µ`.
     */
    private static function padLeft(string $value, int $width): string
    {
        return \str_repeat(' ', \max(0, $width - Helper::width($value))) . $value;
    }

    /**
     * Shortened from the start: the end of a namespaced name tells the most.
     */
    private static function functionName(string $name): string
    {
        \strlen($name) > self::NAME_WIDTH and $name = '…' . \substr($name, -(self::NAME_WIDTH - 1));

        return self::text($name);
    }

    /**
     * Escapes received text for the console: Symfony markup like `<fg=foo>` would be parsed and may throw,
     * and control characters such as ANSI escape sequences would reach the terminal.
     */
    private static function text(string $value): string
    {
        return OutputFormatter::escape((string) \preg_replace('/[\x00-\x1F\x7F]|\xC2[\x80-\x9F]/', '?', $value));
    }

    /**
     * @param 'wt'|'cpu'|'mu'|'ct' $metric
     * @param int<1, max> $limit
     */
    private function renderTopFunctions(
        OutputInterface $output,
        Profile $profile,
        string $metric,
        string $countLabel,
        int $limit,
    ): void {
        $peaks = $profile->peaks;
        $functions = \array_values(Func::aggregate($profile->calls, $peaks));
        \usort(
            $functions,
            static fn(Func $a, Func $b): int => [$b->get("excl_$metric"), $b->get($metric), $a->function]
                <=> [$a->get("excl_$metric"), $a->get($metric), $b->function],
        );
        $total = \count($functions);
        $functions = \array_slice($functions, 0, $limit);

        // The ranking metric gets self and total values with shares, the others the self value only:
        // the full set doesn't fit a terminal. A metric the profile doesn't measure gets no column.
        $labels = ['wt' => 'wall', 'cpu' => 'CPU', 'mu' => 'memory'];
        $others = \array_filter(
            $labels,
            static fn(string $key): bool => $key !== $metric && self::metricOf($peaks->toCost(), $key) > 0,
            \ARRAY_FILTER_USE_KEY,
        );

        $headers = ['Function', $countLabel];
        isset($labels[$metric]) and $headers = [
            ...$headers,
            "Self $labels[$metric]",
            '%',
            "Total $labels[$metric]",
            '%',
        ];
        foreach ($others as $label) {
            $headers[] = "Self $label";
        }

        $rows = [];
        foreach ($functions as $func) {
            $row = [self::functionName($func->function), (string) $func->get('ct')];
            isset($labels[$metric]) and $row = [
                ...$row,
                self::formatMetric($metric, $func->get("excl_$metric")),
                self::formatPercent($func->get("p_excl_$metric")),
                self::formatMetric($metric, $func->get($metric)),
                self::formatPercent($func->get("p_$metric")),
            ];
            foreach ($others as $key => $_) {
                $row[] = self::formatMetric($key, $func->get("excl_$key"));
            }
            $rows[] = $row;
        }

        $title = \sprintf(
            'Top %d of %d functions by self %s',
            \count($functions),
            $total,
            match ($metric) {
                'wt' => 'wall time',
                'cpu' => 'CPU time',
                'mu' => 'memory',
                'ct' => \strtolower($countLabel),
            },
        );

        $output->writeln('');
        $table = (new Table($output))->setHeaderTitle($title)->setHeaders($headers)->setRows($rows);
        $right = (new TableStyle())->setPadType(\STR_PAD_LEFT);
        for ($column = 1; $column < \count($headers); ++$column) {
            $table->setColumnStyle($column, $right);
        }
        $table->render();
    }

    /**
     * Follows the heaviest call from the heaviest root down to a leaf.
     *
     * @param 'wt'|'cpu'|'mu'|'ct' $metric
     */
    private function renderHotPath(OutputInterface $output, Profile $profile, string $metric): void
    {
        $graph = $profile->calls;
        $path = [];
        $edge = self::heaviest($graph->roots(), $metric);
        // The function IDs carry recursion levels, so the graph has no cycles and the walk ends
        while ($edge !== null) {
            $path[] = $edge;
            $edge = self::heaviest($graph->callees($edge->calleeId), $metric);
        }

        if ($path === []) {
            return;
        }

        $total = self::metricOf($profile->peaks->toCost(), $metric);
        $lines = [];
        foreach ($path as $i => $edge) {
            $value = self::metricOf($edge->cost, $metric);
            $lines[] = \sprintf(
                '%s  %s  %s%s',
                self::padLeft(self::formatPercent($total > 0 ? (float) $value / (float) $total * 100.0 : 0.0), 6),
                self::padLeft(self::formatMetric($metric, $value), 11),
                $i === 0 ? '' : '└ ',
                self::functionName($edge->callee),
            );
        }

        $count = \count($lines);
        if ($count > self::PATH_LIMIT) {
            $tail = self::PATH_LIMIT - self::PATH_HEAD - 1;
            $lines = [
                ...\array_slice($lines, 0, self::PATH_HEAD),
                \sprintf('<fg=gray>%21s… %d more calls</>', '', $count - self::PATH_HEAD - $tail),
                ...\array_slice($lines, -$tail),
            ];
        }

        Common::renderHeader3($output, 'Hot path');
        $output->writeln($lines);
    }
}
