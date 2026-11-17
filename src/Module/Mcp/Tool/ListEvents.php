<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Mcp\Tool;

use Buggregator\Trap\Module\Mcp\Tool;
use Buggregator\Trap\Module\Mcp\View\EventViews;
use Buggregator\Trap\Module\Mcp\View\TextCache;
use Buggregator\Trap\Server\Event\EventStore;
use Buggregator\Trap\Server\Event\Record;

/**
 * The events added or updated after a cursor that match a filter, with a summary line and the fields each.
 *
 * @internal
 *
 * @psalm-type ListedEvent = array{
 *     id: non-empty-string,
 *     type: non-empty-string,
 *     project: non-empty-string,
 *     time: string,
 *     summary: non-empty-string,
 *     fields?: array<non-empty-string, scalar|list<scalar>>,
 *     text?: string,
 * }
 */
final class ListEvents implements Tool
{
    public const DEFAULT_LIMIT = 50;
    public const MAX_LIMIT = 200;

    /** Characters of the text of each event with `include: text` when the client sets no limit. */
    public const DEFAULT_TEXT_LENGTH = 2_000;

    public function __construct(
        private readonly EventStore $store,
        private readonly EventViews $views,
        private readonly TextCache $texts,
        private readonly EventFilters $filters,
    ) {}

    #[\Override]
    public function name(): string
    {
        return 'list_events';
    }

    #[\Override]
    public function title(): string
    {
        return 'List events';
    }

    #[\Override]
    public function description(): string
    {
        return 'Lists the events Trap has caught from the running application: dumps, logs, exceptions, mails, '
            . 'HTTP requests and profiles. They are the events the web UI shows. Returns the events added or '
            . 'updated after the `since` cursor, oldest first, each with an ID, a one-line summary and its fields, '
            . 'and a new opaque `cursor`. To see only what the application sends from now on, call it once, keep '
            . 'the cursor, run the code, then call it again with `since` set to that cursor. When `has_more` is '
            . 'true, call again with the returned cursor to get the rest. Narrow the list in one call: by `type`, '
            . '`project`, time (`from`, `to`, such as `5m` for the last five minutes), text (`search`, `regex`) '
            . 'and fields (`where`). The fields by type: ' . $this->filters->fieldsHelp() . '. `order: newest` '
            . 'gives the latest events first. Read an event in full with `get_event`, or get the texts of all the '
            . 'listed ones with `include: text`.';
    }

    #[\Override]
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'since' => [
                    'type' => 'string',
                    'description' => 'The opaque `cursor` of a previous call: only the events changed after it. '
                        . 'All the events when omitted or empty, and for a cursor of an earlier run of Trap.',
                ],
                ...$this->filters->schema(),
                'order' => [
                    'type' => 'string',
                    'enum' => ['oldest', 'newest'],
                    'default' => 'oldest',
                    'description' => '`oldest`: the oldest change first, to page with `cursor`. `newest`: the '
                        . 'latest `limit` events, newest first; `has_more` tells that older ones were left out, '
                        . 'and `cursor` still gives only the events changed after this call.',
                ],
                'include' => [
                    'type' => 'string',
                    'enum' => ['summary', 'text'],
                    'default' => 'summary',
                    'description' => '`text` adds to each event its full text, as `get_event` shows it, cut to '
                        . '`max_length`.',
                ],
                'max_length' => MaxLength::schema(
                    self::DEFAULT_TEXT_LENGTH,
                    'With `include: text`, the most characters of the text of each event; a longer text is cut '
                    . 'with a notice.',
                ),
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => self::MAX_LIMIT,
                    'default' => self::DEFAULT_LIMIT,
                    'description' => 'The most events to return.',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    #[\Override]
    public function outputSchema(): ?array
    {
        $scalar = ['type' => ['string', 'number', 'boolean']];

        return [
            'type' => 'object',
            'properties' => [
                'events' => [
                    'type' => 'array',
                    'description' => 'Oldest change first; newest first with `order: newest`.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'string', 'description' => 'The ID to pass to `get_event`.'],
                            'type' => ['type' => 'string'],
                            'project' => ['type' => 'string'],
                            'time' => ['type' => 'string', 'description' => 'When the event was caught, ISO 8601.'],
                            'summary' => ['type' => 'string'],
                            'fields' => [
                                'type' => 'object',
                                'description' => 'The fields to filter by with `where`; absent when the event has none.',
                                'additionalProperties' => [
                                    'anyOf' => [$scalar, ['type' => 'array', 'items' => $scalar]],
                                ],
                            ],
                            'text' => ['type' => 'string', 'description' => 'The full text, with `include: text`.'],
                        ],
                        'required' => ['id', 'type', 'project', 'time', 'summary'],
                        'additionalProperties' => false,
                    ],
                ],
                'cursor' => ['type' => 'string', 'description' => 'The `since` of the next call.'],
                'has_more' => [
                    'type' => 'boolean',
                    'description' => 'Whether more matching events wait for the next call; with `order: newest`, '
                        . 'whether older matching events were left out.',
                ],
            ],
            'required' => ['events', 'cursor', 'has_more'],
            'additionalProperties' => false,
        ];
    }

    #[\Override]
    public function annotations(): array
    {
        return ['readOnlyHint' => true, 'openWorldHint' => false];
    }

    /**
     * @return array{events: list<ListedEvent>, cursor: non-empty-string, has_more: bool}
     */
    #[\Override]
    public function call(array $arguments): array
    {
        $since = Cursor::since($arguments, $this->store->id, $this->store->cursor());
        $limit = Arguments::integer($arguments, 'limit', 1, self::MAX_LIMIT) ?? self::DEFAULT_LIMIT;
        $filter = $this->filters->fromArguments($arguments);
        $newest = Arguments::choice($arguments, 'order', ['oldest', 'newest']) === 'newest';
        $text = Arguments::choice($arguments, 'include', ['summary', 'text']) === 'text';
        $maxLength = MaxLength::fromArguments($arguments, self::DEFAULT_TEXT_LENGTH);

        $cursor = $this->store->cursor();
        $records = $this->store->changedSince($since);
        $newest and $records = \array_reverse($records);
        $events = [];
        $hasMore = false;
        $last = 0;
        foreach ($records as $record) {
            if (!$filter->matches($record)) {
                continue;
            }

            if (\count($events) === $limit) {
                $hasMore = true;
                // The rest comes with the next call, which takes the last listed change as its cursor;
                // the older events left out by `newest` never come, so the cursor stays at the latest change
                $newest or $cursor = $last;
                break;
            }

            $last = $record->seq;
            $events[] = $this->describe($record, $text ? $maxLength : null);
        }

        return ['events' => $events, 'cursor' => Cursor::encode($this->store->id, $cursor), 'has_more' => $hasMore];
    }

    /**
     * @param int<0, max>|null $maxLength Characters of the text to add; no text when null.
     *
     * @return ListedEvent
     */
    private function describe(Record $record, ?int $maxLength): array
    {
        $event = $record->event;
        $result = [
            'id' => $event->uuid,
            'type' => $event->type,
            'project' => $event->projectKey(),
            'time' => Time::format($event->timestamp),
            'summary' => $this->views->summary($event),
        ];
        $fields = $this->views->fields($event);
        // An empty array is encoded as a JSON list, not as the object the schema declares
        $fields === [] or $result['fields'] = $fields;
        $maxLength === null or $result['text'] = MaxLength::cut($this->texts->text($record), $maxLength);

        return $result;
    }
}
