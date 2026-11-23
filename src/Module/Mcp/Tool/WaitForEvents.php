<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Mcp\Tool;

use Buggregator\Trap\Module\Mcp\Caller;
use Buggregator\Trap\Module\Mcp\WaitingTool;
use Buggregator\Trap\Server\Event\EventStore;

/**
 * Waits until `list_events` with the same `since` and filter, `order: oldest` and `limit: count` lists
 * `count` events, and returns that list.
 *
 * The call suspends the fiber of its request and the server goes on meanwhile. On each resume it checks
 * only that the store has changed since the last pass, so a wait without new events costs nearly nothing;
 * a change gets the list collected again from the original `since`, within the {@see Scan} budget. The
 * {@see CollectMemo} keeps that cheap: a record unchanged since a pass is not filtered again, nor described
 * again when it is listed. A pass that runs out of time is followed by another one, which gets further over the
 * records already checked. A pass stops when the client disconnects.
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
            . '`list_events`. Call it after triggering the code, such as running the tests or sending a request. '
            . 'The result is what `list_events` with the same arguments and `limit: count` returns once it holds '
            . '`count` events, or once the texts of `include: text` fill it; after `timeout` seconds (%d by '
            . 'default, %d at most) it is what that call returns then, possibly no events, with `timed_out: true`, '
            . 'which is not an error. Without `since` it waits for the events changed after the call starts; '
            . 'events the code sends before that are missed, so to be safe take a `cursor` from `list_events` '
            . '(or from an earlier wait) before triggering the code, and pass it as `since`. Pass the returned '
            . '`cursor` to the next wait or `list_events` to miss nothing. Traces, Sentry transactions among '
            . 'them, are not events and never arrive here: read them with `list_traces`. Neither does Sentry user '
            . 'feedback, an envelope of only feedback included: read it with `list_feedback`.',
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
            'description' => 'As with `list_events`: whether more matching events wait for the next call with '
                . 'the returned cursor, past `count`, past the texts of `include: text`, or left unchecked as the '
                . 'time ran out.',
        ];
        $schema['properties']['timed_out'] = [
            'type' => 'boolean',
            'description' => 'Whether the time ran out, or the client disconnected, before the result held `count` '
                . 'events or filled with texts; `events` has those that arrived.',
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
        $page = null;
        $memo = new CollectMemo();
        $disconnected = $this->caller->isDisconnected(...);
        while (true) {
            $seen = $this->store->cursor();
            $page = $this->list->collect(
                $since,
                $filter,
                $count,
                $text ? $maxLength : null,
                // The full scan budget: a short `timeout` still gets the backlog of an older `since` checked
                budget: $page === null ? null : (float) \max(0, $deadline - \hrtime(true)) / 1e9,
                stop: $disconnected,
                memo: $memo,
            );
            // Cut by `count` or by the texts: the events arriving later can't change the result
            $complete = \count($page['events']) >= $count || ($page['has_more'] && !$page['unchecked']);
            if ($complete || !$canWait || $this->isOver($deadline)) {
                return $this->result($page, !$complete);
            }

            // A pass out of time is followed by another one at once, which skips the records this one checked.
            // An event arriving during a pass moves the cursor past `$seen` and gets a pass too
            do {
                \Fiber::suspend();
            } while (!$page['unchecked'] && $this->store->cursor() === $seen && !$this->isOver($deadline));

            if ($this->isOver($deadline)) {
                return $this->result($this->withoutDeleted($page), true);
            }
        }
    }

    private function isOver(int $deadline): bool
    {
        return \hrtime(true) >= $deadline || $this->caller->isDisconnected();
    }

    /**
     * Drops the events deleted since the page was collected: a deletion doesn't move the cursor of the store,
     * so it wakes no wait.
     *
     * @param array{events: list<ListedEvent>, cursor: int<0, max>, has_more: bool, unchecked: bool} $page
     *
     * @return array{events: list<ListedEvent>, cursor: int<0, max>, has_more: bool, unchecked: bool}
     */
    private function withoutDeleted(array $page): array
    {
        $page['events'] = \array_values(\array_filter(
            $page['events'],
            fn(array $event): bool => $this->store->record($event['id']) !== null,
        ));

        return $page;
    }

    /**
     * @param array{events: list<ListedEvent>, cursor: int<0, max>, has_more: bool, unchecked: bool} $page
     *
     * @return array{events: list<ListedEvent>, cursor: non-empty-string, has_more: bool, timed_out: bool}
     */
    private function result(array $page, bool $timedOut): array
    {
        return [
            'events' => $page['events'],
            'cursor' => Cursor::encode($this->store->id, $page['cursor']),
            'has_more' => $page['has_more'],
            'timed_out' => $timedOut,
        ];
    }
}
