<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\XDebugTrace\Trace;

use Buggregator\Trap\Support\TimeSlice;

/**
 * What one streaming pass learns about a trace. Times are seconds, memory is bytes.
 *
 * @psalm-type FuncStat = array{func: string, user: bool, count: int, self: float, incl: float}
 * @psalm-type Call = array{
 *     nr: int, func: string, file: string, line: int, level: int,
 *     start: float, incl: float, self: float, memory: int, offset: int
 * }
 * @psalm-type SummaryData = array{
 *     xdebug_version: string, file_format: int, start: string, end: string, end_time: float, end_memory: int,
 *     calls: int, user_calls: int, max_depth: int, duration: float, peak_memory: int, assignments: int,
 *     returns: bool, truncated: bool, main: string, top: list<FuncStat>, slowest: list<Call>
 * }
 *
 * @internal
 */
final class Summary
{
    /** How many functions and calls the summary keeps */
    public const TOP_N = 20;

    /**
     * Reads the whole trace once. A trace over the limit is summarized up to it, as truncated.
     *
     * @param int $limit Uncompressed bytes to read at most; 0 for no limit.
     *
     * @return SummaryData
     *
     * @throws TraceError When the file is not a trace in the format 1.
     * @throws \RuntimeException When the file can't be opened.
     */
    public static function summarize(string $path, int $limit = 0, ?TimeSlice $slice = null): array
    {
        $reader = TraceReader::open($path, $limit, $slice);
        $calls = $userCalls = $maxDepth = $peak = $assignments = 0;
        $returns = $truncated = false;
        $main = '';
        $last = 0.0;
        /** @var array<string, array{func: string, user: bool, count: int, self: float, incl: float}> $funcs */
        $funcs = [];
        $slowest = new SlowestCalls(self::TOP_N);
        /** @var list<array{call: Call, user: bool, mem: int, child: float}> $stack */
        $stack = [];

        $close = static function (array $frame, float $end, int $memory) use (&$stack, &$funcs, $slowest): void {
            /** @var array{call: Call, user: bool, mem: int, child: float} $frame */
            $call = $frame['call'];
            $call['incl'] = \max($end - $call['start'], 0.0);
            $call['self'] = \max($call['incl'] - $frame['child'], 0.0);
            $call['memory'] = $memory - $frame['mem'];
            $stat = $funcs[$call['func']] ?? ['func' => $call['func'], 'user' => $frame['user'], 'count' => 0, 'self' => 0.0, 'incl' => 0.0];
            ++$stat['count'];
            $stat['self'] += $call['self'];
            $stat['incl'] += $call['incl'];
            $funcs[$call['func']] = $stat;
            $stack === [] or $stack[\count($stack) - 1]['child'] += $call['incl'];
            $slowest->push($call);
        };

        while (true) {
            try {
                $record = $reader->next();
            } catch (TraceError $e) {
                if ($e->reason !== TraceError::TOO_LARGE) {
                    throw $e;
                }
                $truncated = true;
                break;
            }
            if ($record === null) {
                break;
            }

            $record->memory > $peak and $peak = $record->memory;
            switch ($record->kind) {
                case Record::ENTRY:
                    $last = $record->time;
                    ++$calls;
                    $record->user and ++$userCalls;
                    $record->level > $maxDepth and $maxDepth = $record->level;
                    $record->func === '{main}' && $main === '' and $main = $record->file;
                    $stack[] = [
                        'call' => [
                            'nr' => $record->nr,
                            'func' => $record->func,
                            'file' => $record->file,
                            'line' => $record->line,
                            'level' => $record->level,
                            'start' => $record->time,
                            'incl' => 0.0,
                            'self' => 0.0,
                            'memory' => 0,
                            'offset' => $reader->offset(),
                        ],
                        'user' => $record->user,
                        'mem' => $record->memory,
                        'child' => 0.0,
                    ];
                    break;
                case Record::EXIT:
                    $last = $record->time;
                    // Frames left open above the exited call, as an exception unwinds, close at the same time
                    for ($i = \count($stack) - 1; $i >= 0; --$i) {
                        if ($stack[$i]['call']['nr'] === $record->nr) {
                            while (\count($stack) > $i) {
                                $frame = \array_pop($stack);
                                $frame === null or $close($frame, $record->time, $record->memory);
                            }
                            break;
                        }
                    }
                    break;
                case Record::RETURN:
                    $returns = true;
                    break;
                case Record::ASSIGN:
                    ++$assignments;
                    break;
            }
        }

        $last = \max($last, $reader->endTime);
        $peak = \max($peak, $reader->endMemory);
        $stack !== [] && $reader->end === '' and $truncated = true;
        while ($stack !== []) {
            $frame = \array_pop($stack);
            $close($frame, $last, $frame['mem']);
        }
        $calls === 0 && $reader->fileFormat === 0 and throw TraceError::notFormat1();

        $top = \array_values($funcs);
        \usort($top, static fn(array $a, array $b): int => [$b['self'], $a['func']] <=> [$a['self'], $b['func']]);

        return [
            'xdebug_version' => $reader->version,
            'file_format' => $reader->fileFormat,
            'start' => $reader->start,
            'end' => $reader->end,
            'end_time' => $reader->endTime,
            'end_memory' => $reader->endMemory,
            'calls' => $calls,
            'user_calls' => $userCalls,
            'max_depth' => $maxDepth,
            'duration' => $last,
            'peak_memory' => $peak,
            'assignments' => $assignments,
            'returns' => $returns,
            'truncated' => $truncated,
            'main' => $main,
            'top' => \array_slice($top, 0, self::TOP_N),
            'slowest' => $slowest->sorted(),
        ];
    }
}
