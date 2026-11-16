<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Mcp\Tool;

use Buggregator\Trap\Module\Mcp\Tool;
use Buggregator\Trap\Module\Mcp\View\EventViews;
use Buggregator\Trap\Server\Application\Module\Modules;
use Buggregator\Trap\Server\Event\EventStore;

/**
 * The events added or updated after a cursor, oldest change first, with a summary line each.
 *
 * @internal
 */
final class ListEvents implements Tool
{
    public const DEFAULT_LIMIT = 50;
    public const MAX_LIMIT = 200;

    /** @var list<non-empty-string> */
    private readonly array $types;

    public function __construct(
        private readonly EventStore $store,
        private readonly EventViews $views,
        Modules $modules,
    ) {
        $this->types = EventFilter::knownTypes($modules);
    }

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
            . 'updated after the `since` cursor, oldest first, each with an ID and a one-line summary, and a new '
            . 'opaque `cursor`. To see only what the application sends from now on, call it once, keep the cursor, run '
            . 'the code, then call it again with `since` set to that cursor. When `has_more` is true, call again '
            . 'with the returned cursor to get the rest. Read an event in full with `get_event`.';
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
                ...EventFilter::schema($this->types),
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
        return [
            'type' => 'object',
            'properties' => [
                'events' => [
                    'type' => 'array',
                    'description' => 'Oldest change first.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'string', 'description' => 'The ID to pass to `get_event`.'],
                            'type' => ['type' => 'string'],
                            'project' => ['type' => 'string'],
                            'time' => ['type' => 'string', 'description' => 'When the event was caught, ISO 8601.'],
                            'summary' => ['type' => 'string'],
                        ],
                        'required' => ['id', 'type', 'project', 'time', 'summary'],
                        'additionalProperties' => false,
                    ],
                ],
                'cursor' => ['type' => 'string', 'description' => 'The `since` of the next call.'],
                'has_more' => ['type' => 'boolean', 'description' => 'Whether more changed events wait for the next call.'],
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
     * @return array{
     *     events: list<array{id: non-empty-string, type: non-empty-string, project: non-empty-string, time: string, summary: non-empty-string}>,
     *     cursor: non-empty-string,
     *     has_more: bool,
     * }
     */
    #[\Override]
    public function call(array $arguments): array
    {
        $since = Cursor::since($arguments, $this->store->id, $this->store->cursor());
        $limit = Arguments::integer($arguments, 'limit', 1, self::MAX_LIMIT) ?? self::DEFAULT_LIMIT;
        $filter = EventFilter::fromArguments($arguments, $this->types);

        $cursor = $this->store->cursor();
        $events = [];
        $hasMore = false;
        $last = 0;
        foreach ($this->store->changedSince($since) as $record) {
            if (!$filter->matches($record->event)) {
                continue;
            }

            if (\count($events) === $limit) {
                // The rest comes with the next call, which takes the last listed change as its cursor
                $hasMore = true;
                $cursor = $last;
                break;
            }

            $last = $record->seq;
            $events[] = [
                'id' => $record->event->uuid,
                'type' => $record->event->type,
                'project' => $record->event->projectKey(),
                'time' => Time::format($record->event->timestamp),
                'summary' => $this->views->summary($record->event),
            ];
        }

        return ['events' => $events, 'cursor' => Cursor::encode($this->store->id, $cursor), 'has_more' => $hasMore];
    }
}
