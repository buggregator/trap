<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Mcp\Tool;

use Buggregator\Trap\Module\Mcp\Caller;
use Buggregator\Trap\Module\Mcp\WaitingTool;
use Buggregator\Trap\Server\Event\EventStore;

/**
 * Waits until events matching a filter arrive, and lists them as `list_events` does.
 *
 * The call suspends the fiber of its request and the server goes on meanwhile. On each resume it checks
 * only that the store has changed since the last check, so a wait without new events costs nearly nothing;
 * a change gets the changed records checked against the filter, within the {@see Scan} budget.
 *
 * @internal
 *
 * @psalm-import-type ListedEvent from ListEvents
 */
final class WaitForEvents implements WaitingTool
{
    public const DEFAULT_TIMEOUT = 30.0;

    /**
     * Seconds at most: the common MCP clients give up on a call after 60 seconds, as the TypeScript SDK
     * and Codex do by default, and a wait answered after that is lost.
     */
    public const MAX_TIMEOUT = 50.0;

    public const DEFAULT_COUNT = 1;
    public const MAX_COUNT = 50;

    public function __construct(
        private readonly EventStore $store,
        private readonly ListEvents $list,
        private readonly EventFilterArguments $filters,
        private readonly Caller $caller,
    ) {}

    #[\Override]
    public function name(): string
    {
        return 'wait_for_events';
    }

    #[\Override]
    public function title(): string
    {
        return 'Wait for events';
    }

    #[\Override]
    public function description(): string
    {
        return \sprintf(
            'Waits until the running application sends events matching the filter, instead of polling '
            . '`list_events`, and lists them as `list_events` does. Call it after triggering the code, such as '
            . 'running the tests or sending a request: it returns as soon as `count` matching events have arrived, '
            . 'or after `timeout` seconds (%d by default, %d at most) with those that arrived meanwhile and '
            . '`timed_out: true`, which is not an error. Without `since` it waits for the events changed after the '
            . 'call starts; events the code sends before that are missed, so to be safe take a `cursor` from '
            . '`list_events` (or from an earlier wait) before triggering the code, and pass it as `since`. The '
            . 'returned `cursor` goes on from the last returned event: pass it to the next wait or `list_events` '
            . 'to miss nothing. The filter is the one of `list_events`.',
            self::DEFAULT_TIMEOUT,
            self::MAX_TIMEOUT,
        );
    }

    #[\Override]
    public function inputSchema(): array
    {
        $list = $this->list->inputSchema()['properties'];
        \assert(\is_array($list) && \is_array($list['include']));

        return [
            'type' => 'object',
            'properties' => [
                'since' => [
                    'type' => 'string',
                    'description' => 'The opaque `cursor` of a previous call: the events changed after it count, '
                        . 'also the ones that came before this call. When omitted or empty, only the events changed '
                        . 'after this call starts. All the events for a cursor of an earlier run of Trap.',
                ],
                ...$this->filters->schema(),
                'count' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => self::MAX_COUNT,
                    'default' => self::DEFAULT_COUNT,
                    'description' => 'The number of matching events to wait for; the most events to return.',
                ],
                'timeout' => [
                    'type' => 'number',
                    'minimum' => 0,
                    'maximum' => self::MAX_TIMEOUT,
                    'default' => self::DEFAULT_TIMEOUT,
                    'description' => 'Seconds to wait at most. To wait longer, call again with the returned cursor.',
                ],
                'include' => [
                    ...$list['include'],
                    'description' => \sprintf(
                        '`text` adds to each event its full text, as `get_event` shows it, cut to `max_length`. '
                        . 'The texts of a response take %d characters at most: the events past that are left '
                        . 'to the next call, as past `count`.',
                        ListEvents::MAX_TOTAL_TEXT_LENGTH,
                    ),
                ],
                'max_length' => $list['max_length'],
            ],
            'additionalProperties' => false,
        ];
    }

    #[\Override]
    public function outputSchema(): ?array
    {
        $schema = $this->list->outputSchema();
        \assert($schema !== null && \is_array($schema['properties']) && \is_array($schema['required']));
        \assert(\is_array($schema['properties']['events']));
        $schema['properties']['events']['description'] = 'Oldest change first.';
        $schema['properties']['has_more'] = [
            'type' => 'boolean',
            'description' => 'Whether more matching events wait for `list_events` or the next wait with the '
                . 'returned cursor: `count` events were found and there are more, the texts of `include: text` '
                . 'filled the response, or the time ran out before all the changed events were checked, '
                . 'with `timed_out: true` only when fewer than `count` events were found.',
        ];
        $schema['properties']['timed_out'] = [
            'type' => 'boolean',
            'description' => 'Whether the time ran out before `count` matching events arrived; `events` has those '
                . 'that did.',
        ];
        $schema['required'][] = 'timed_out';

        return $schema;
    }

    #[\Override]
    public function annotations(): array
    {
        return ['readOnlyHint' => true, 'openWorldHint' => false];
    }

    /**
     * Outside a fiber it can't wait: it checks the events once.
     *
     * @return array{events: list<ListedEvent>, cursor: non-empty-string, has_more: bool, timed_out: bool}
     */
    #[\Override]
    public function call(array $arguments): array
    {
        $now = $this->store->cursor();
        $since = ($arguments['since'] ?? '') === ''
            ? $now
            : Cursor::since($arguments, $this->store->id, $now);
        $filter = $this->filters->parse($arguments);
        $count = Arguments::integer($arguments, 'count', 1, self::MAX_COUNT) ?? self::DEFAULT_COUNT;
        $timeout = Arguments::number($arguments, 'timeout') ?? self::DEFAULT_TIMEOUT;
        $timeout <= self::MAX_TIMEOUT or throw new \InvalidArgumentException(
            \sprintf('`timeout` must be a number from 0 to %d.', self::MAX_TIMEOUT),
        );
        $text = Arguments::choice($arguments, 'include', ['summary', 'text']) === 'text';
        $maxLength = MaxLength::fromArguments($arguments, ListEvents::DEFAULT_TEXT_LENGTH, ListEvents::MAX_TEXT_LENGTH);

        $deadline = \hrtime(true) + (int) ($timeout * 1e9);
        $canWait = \Fiber::getCurrent() !== null;
        /** @var array<non-empty-string, ListedEvent> $events */
        $events = [];
        /** @var array<non-empty-string, int<0, max>> $textLengths */
        $textLengths = [];
        $first = true;
        while (true) {
            $limit = \max(0, $count - \count($events));
            $page = $this->list->collect(
                $since,
                $filter,
                $limit,
                $text ? $maxLength : null,
                kept: $textLengths,
                // The full scan budget: a short `timeout` still gets the backlog of an older `since` checked
                budget: $first ? null : (float) \max(0, $deadline - \hrtime(true)) / 1e9,
            );
            $first = false;
            foreach ($page['events'] as $event) {
                // An event updated meanwhile, as a Ray message is, moves to its latest change and counts once
                unset($events[$event['id']]);
                $events[$event['id']] = $event;
                $textLengths[$event['id']] = \mb_strlen($event['text'] ?? '');
            }

            $since = $page['cursor'];
            $limited = $page['has_more'] && !$page['unchecked'] && \count($page['events']) === $limit;
            $filled = $page['has_more'] && !$page['unchecked'] && !$limited;
            if ($page['rejected'] !== null) {
                // The update that didn't fit lies past the cursor: the next call lists it at its latest text
                unset($events[$page['rejected']], $textLengths[$page['rejected']]);
            }

            $done = \count($events) >= $count;
            $over = !$canWait || \hrtime(true) >= $deadline || $this->caller->isDisconnected();
            if ($done || $filled || $over) {
                return $this->result($events, $since, $page['has_more'], !$done && !$filled);
            }

            // The rest of the changes to check right away: the time ran out, or updated events took the limit,
            // and the cursor has gone past them
            if ($page['has_more']) {
                continue;
            }

            while ($this->store->cursor() <= $since && \hrtime(true) < $deadline && !$this->caller->isDisconnected()) {
                \Fiber::suspend();
            }

            if ($this->caller->isDisconnected()) {
                return $this->result($events, $since, false, true);
            }
        }
    }

    /**
     * @param array<non-empty-string, ListedEvent> $events
     * @param int<0, max> $cursor
     *
     * @return array{events: list<ListedEvent>, cursor: non-empty-string, has_more: bool, timed_out: bool}
     */
    private function result(array $events, int $cursor, bool $hasMore, bool $timedOut): array
    {
        return [
            'events' => \array_values($events),
            'cursor' => Cursor::encode($this->store->id, $cursor),
            'has_more' => $hasMore,
            'timed_out' => $timedOut,
        ];
    }
}
