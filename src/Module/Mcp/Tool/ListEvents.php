<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Mcp\Tool;

use Buggregator\Trap\Module\Mcp\Tool;
use Buggregator\Trap\Module\Mcp\View\EventViews;
use Buggregator\Trap\Server\Application\Module\Modules;
use Buggregator\Trap\Server\Event\Event;
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

    /**
     * ISO 8601 in UTC with milliseconds.
     */
    public static function time(Event $event): string
    {
        $time = \DateTimeImmutable::createFromFormat('U.u', \sprintf('%.6F', $event->timestamp));

        return $time === false ? '' : $time->format('Y-m-d\TH:i:s.vP');
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
        $since = $this->since($arguments);
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
                'time' => self::time($record->event),
                'summary' => $this->views->summary($record->event),
            ];
        }

        return ['events' => $events, 'cursor' => $this->store->id . ':' . $cursor, 'has_more' => $hasMore];
    }

    /**
     * Reads the `since` cursor, `<store ID>:<sequence number>`.
     *
     * @param array<array-key, mixed> $arguments
     *
     * @return int<0, max> The sequence number; 0, for all the events, when the cursor is empty or of another
     *         Trap process, whose events are gone.
     *
     * @throws \InvalidArgumentException
     */
    private function since(array $arguments): int
    {
        /** @var mixed $since */
        $since = $arguments['since'] ?? '';
        \is_string($since) or throw new \InvalidArgumentException('`since` must be a string.');

        [$id, $seq] = \explode(':', $since, 2) + [1 => ''];
        if ($id !== $this->store->id || !\ctype_digit($seq)) {
            return 0;
        }

        $seq = (int) $seq;

        return $seq >= 0 && $seq <= $this->store->cursor() ? $seq : 0;
    }
}
