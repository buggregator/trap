<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\XDebugTrace\Trace;

use Buggregator\Trap\Support\TimeSlice;

/**
 * Walks the call tree of a trace one level at a time, or searches it, by scanning the file: no index is built.
 * Offsets of entry lines identify the calls.
 *
 * @internal
 */
final class CallTree
{
    /** Nodes per page at most */
    public const MAX_NODES = 1000;

    /**
     * @param int $sizeLimit Uncompressed bytes to read at most; 0 for no limit.
     */
    public function __construct(
        private readonly string $path,
        private readonly int $sizeLimit = 0,
        private readonly ?TimeSlice $slice = null,
    ) {}

    /**
     * Lists one level of calls.
     *
     * @param int $parent Below 0: the top-level calls, the ones that start while no listed call is open, so a trace
     *        started by `xdebug_start_trace()` deep in a script (first level 3, later 2) lists right. Otherwise
     *        the direct callees of the call whose entry line starts there.
     * @param int $from Below 0 to start from the first call; otherwise a {@see Page::$next} of the same parent.
     * @param int $limit Nodes at most, 1 to {@see MAX_NODES}; out of the range is {@see MAX_NODES}.
     *
     * @throws TraceError
     */
    public function children(int $parent = -1, int $from = -1, int $limit = self::MAX_NODES): Page
    {
        $limit = $limit <= 0 || $limit > self::MAX_NODES ? self::MAX_NODES : $limit;
        $reader = TraceReader::open($this->path, $this->sizeLimit, $this->slice);
        $page = new Page();

        $entryAt = static function (int $offset) use ($reader): Record {
            $reader->skipTo($offset);
            $record = $reader->next();
            $record?->kind === Record::ENTRY && $reader->offset() === $offset or throw TraceError::badOffset();

            return $record;
        };
        $parent >= 0 and $page->parent = new Node($entryAt($parent), $parent);
        $first = null;
        if ($from >= 0) {
            $first = $entryAt($from);
            $page->parent !== null && $first->level !== $page->parent->entry->level + 1
                and throw TraceError::badOffset();
        }

        /** The listed call open now, or the last one closed */
        $cur = null;
        /** @var array<int, float> $childOpen Start times of the open direct callees of $cur, by number */
        $childOpen = [];
        $add = static function (Record $record, int $offset) use ($page, $limit, &$cur, &$childOpen): bool {
            if (\count($page->nodes) === $limit) {
                $page->next = $offset;
                return false;
            }
            $page->nodes[] = $cur = new Node($record, $offset);
            $childOpen = [];
            return true;
        };
        $first === null or $add($first, $from);

        while (true) {
            try {
                $record = $reader->next();
            } catch (TraceError $e) {
                $e->reason === TraceError::TOO_LARGE or throw $e;
                $page->truncated = true;
                break;
            }

            if ($record === null) {
                // The end of a whole trace is no cut, unless it ends with a listed call open and no TRACE END:
                // `xdebug_stop_trace()` leaves its callers open in a whole trace
                $page->truncated = $reader->end === ''
                    && (($page->parent !== null && !$page->parent->done) || ($cur !== null && !$cur->done));
                break;
            }

            if ($record->kind === Record::ASSIGN) {
                continue;
            }

            $p = $page->parent;
            if ($p !== null && $record->level <= $p->entry->level) {
                if ($record->kind === Record::EXIT && $record->nr === $p->entry->nr) {
                    $p->exit($record);
                    // Xdebug writes the return value after the exit line
                    try {
                        $next = $reader->next();
                        $next?->kind === Record::RETURN && $next->nr === $p->entry->nr
                            and $p->return = Node::clip($next->value);
                    } catch (TraceError) {
                    }
                }
                break;
            }

            $open = $cur !== null && !$cur->done;
            switch (true) {
                case $record->kind === Record::ENTRY && !$open:
                    if (!$add($record, $reader->offset())) {
                        return $page;
                    }
                    break;
                case $cur === null:
                    // An exit of a caller above the traced part
                    break;
                case $open && $record->nr === $cur->entry->nr && $record->kind === Record::EXIT:
                    $cur->exit($record);
                    break;
                case $record->kind === Record::RETURN && $record->nr === $cur?->entry->nr:
                    $cur->return = Node::clip($record->value);
                    break;
                case $open && $record->level === $cur->entry->level + 1 && $record->kind === Record::ENTRY:
                    ++$cur->children;
                    $childOpen[$record->nr] = $record->time;
                    break;
                case $open && $record->level === $cur->entry->level + 1 && $record->kind === Record::EXIT:
                    if (isset($childOpen[$record->nr])) {
                        $cur->childTime += \max($record->time - $childOpen[$record->nr], 0.0);
                        unset($childOpen[$record->nr]);
                    }
                    break;
            }
        }

        $p = $page->parent;
        if ($p !== null && $p->done && $from < 0 && $page->next === null) {
            // The listed callees end with the parent, so it has exactly as many
            $p->children = \count($page->nodes);
            $sum = 0.0;
            foreach ($page->nodes as $node) {
                $sum += $node->incl;
            }
            $p->self = \max($p->incl - $sum, 0.0);
        }

        return $page;
    }

    /**
     * Lists the calls whose function name or file contains the query, case-insensitively, in trace order.
     *
     * Each match carries its callers from the top level down, so a viewer can open the tree to it: that is why
     * the scan always starts at the beginning, also for `from`. Past the limit the scan runs on only until
     * the matches taken are closed, to get their times.
     *
     * @param non-empty-string $query
     * @param int $from 0 for the start; otherwise a {@see Page::$next} of the same query.
     * @param int $limit Nodes at most, 1 to {@see MAX_NODES}; out of the range is {@see MAX_NODES}.
     *
     * @throws TraceError
     */
    public function search(string $query, int $from = 0, int $limit = self::MAX_NODES): Page
    {
        $limit = $limit <= 0 || $limit > self::MAX_NODES ? self::MAX_NODES : $limit;
        $reader = TraceReader::open($this->path, $this->sizeLimit, $this->slice);
        $page = new Page();
        /** @var list<array{nr: int, func: string, offset: int}> $callers Open calls, outermost first */
        $callers = [];
        /** @var array<int, int> $callerAt Index of an open call in the callers, by number */
        $callerAt = [];
        /** @var array<int, int> $open Index in the nodes of an open match, by number */
        $open = [];
        /** @var array<int, array<int, int>> $matchesAt Numbers of the open matches, by level */
        $matchesAt = [];
        /** @var array<int, int> $levelOf Level of an open match, by number */
        $levelOf = [];
        /** @var array<int, float> $starts Start of an open call one level below a match, by number */
        $starts = [];
        /** @var array<int, int> $childOf Number of the open match a direct callee is called by, by number */
        $childOf = [];
        // A return value follows the exit line: the match closed last waits one record for it,
        // and a full page ends one record later
        $closedNr = $closedAt = -1;
        $finish = false;

        while (true) {
            try {
                $record = $reader->next();
            } catch (TraceError $e) {
                $e->reason === TraceError::TOO_LARGE or throw $e;
                $page->truncated = true;
                break;
            }

            if ($record === null) {
                $page->truncated = $reader->end === '' && $open !== [];
                break;
            }

            $record->kind === Record::RETURN && $record->nr === $closedNr
                and $page->nodes[$closedAt]->return = Node::clip($record->value);
            $closedNr = -1;
            if ($finish) {
                return $page;
            }

            switch ($record->kind) {
                case Record::ENTRY:
                    $offset = $reader->offset();
                    $callerAt[$record->nr] = \count($callers);
                    $callers[] = ['nr' => $record->nr, 'func' => $record->func, 'offset' => $offset];
                    foreach ($matchesAt[$record->level - 1] ?? [] as $nr) {
                        ++$page->nodes[$open[$nr]]->children;
                        $starts[$record->nr] = $record->time;
                        $childOf[$record->nr] = $nr;
                    }
                    if ($page->next !== null || $offset < $from) {
                        break;
                    }
                    if (\stripos($record->func, $query) === false && \stripos($record->file, $query) === false) {
                        break;
                    }
                    if (\count($page->nodes) === $limit) {
                        $page->next = $offset;
                        if ($open === []) {
                            return $page;
                        }
                        break;
                    }
                    $node = new Node($record, $offset);
                    $node->path = \array_slice($callers, 0, -1);
                    $page->nodes[] = $node;
                    $open[$record->nr] = \count($page->nodes) - 1;
                    // A malformed trace may reuse the number of a match still open at another level
                    unset($matchesAt[$levelOf[$record->nr] ?? -1][$record->nr]);
                    $levelOf[$record->nr] = $record->level;
                    $matchesAt[$record->level][$record->nr] = $record->nr;
                    break;
                case Record::EXIT:
                    // Exits of callers above the traced part match nothing and leave the stack as it is. Found by
                    // number, not by a scan: exits of calls never entered would make a malformed trace quadratic
                    $k = $callerAt[$record->nr] ?? null;
                    if ($k !== null && ($callers[$k]['nr'] ?? null) === $record->nr) {
                        foreach (\array_splice($callers, $k) as $gone) {
                            unset($callerAt[$gone['nr']]);
                        }
                    }
                    if (isset($childOf[$record->nr])) {
                        $caller = $childOf[$record->nr];
                        isset($open[$caller]) and $page->nodes[$open[$caller]]->childTime
                            += \max($record->time - $starts[$record->nr], 0.0);
                        unset($childOf[$record->nr], $starts[$record->nr]);
                    }
                    if (isset($open[$record->nr])) {
                        $i = $open[$record->nr];
                        $page->nodes[$i]->exit($record);
                        unset($open[$record->nr], $matchesAt[$levelOf[$record->nr]][$record->nr], $levelOf[$record->nr]);
                        $closedNr = $record->nr;
                        $closedAt = $i;
                        $finish = $page->next !== null && $open === [];
                    }
                    break;
                case Record::RETURN:
                    isset($open[$record->nr]) and $page->nodes[$open[$record->nr]]->return = Node::clip($record->value);
                    break;
            }
        }

        return $page;
    }
}
