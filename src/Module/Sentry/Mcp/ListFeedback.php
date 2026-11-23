<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Sentry\Mcp;

use Buggregator\Trap\Module\Mcp\Tool;
use Buggregator\Trap\Module\Mcp\Tool\Arguments;
use Buggregator\Trap\Module\Mcp\Tool\Time;
use Buggregator\Trap\Server\Event\Event;
use Buggregator\Trap\Server\Event\EventStore;
use Buggregator\Trap\Server\Telemetry\Feedback;
use Buggregator\Trap\Server\Telemetry\TelemetryStore;

/**
 * Sentry user feedback: it makes no event, as in buggregator/server, so the event tools don't list it.
 *
 * @internal
 */
final class ListFeedback implements Tool
{
    public const DEFAULT_LIMIT = 20;
    public const MAX_LIMIT = 100;

    public function __construct(
        private readonly TelemetryStore $store,
        private readonly EventStore $events,
    ) {}

    #[\Override]
    public function name(): string
    {
        return 'list_feedback';
    }

    #[\Override]
    public function title(): string
    {
        return 'List Sentry user feedback';
    }

    #[\Override]
    public function description(): string
    {
        return 'Lists what users wrote in the Sentry feedback widget (`feedback` envelope items) or in legacy '
            . '`user_report` items, newest first: name, email, message, page URL and the error it is about. '
            . 'Feedback makes no event, so `list_events` does not show it. Narrow by `project` or by `event_id`, '
            . 'the Sentry event ID of an error; `error_uuid` is the ID of that error for `get_event` while Trap '
            . 'keeps it.';
    }

    #[\Override]
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'project' => [
                    'type' => 'string',
                    'description' => \sprintf(
                        'Only the feedback of this project; feedback sent without one belongs to `%s`.',
                        Event::DEFAULT_PROJECT,
                    ),
                ],
                'event_id' => [
                    'type' => 'string',
                    'description' => 'Only the feedback on the error of this Sentry event ID, with or without dashes.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => self::MAX_LIMIT,
                    'default' => self::DEFAULT_LIMIT,
                    'description' => 'The most entries to return.',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    #[\Override]
    public function outputSchema(): ?array
    {
        $string = ['type' => 'string'];

        return [
            'type' => 'object',
            'properties' => [
                'feedback' => [
                    'type' => 'array',
                    'description' => 'The newest first.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'project' => $string,
                            'event_id' => $string + [
                                'description' => 'The Sentry event ID of the error it is about; empty for none.',
                            ],
                            'error_uuid' => [
                                'type' => ['string', 'null'],
                                'description' => 'The ID of that error for `get_event`; null when Trap has not it.',
                            ],
                            'name' => $string,
                            'email' => $string,
                            'message' => $string,
                            'url' => $string,
                            'source' => ['type' => 'string', 'enum' => ['feedback', 'user_report']],
                            'received' => $string + ['description' => 'ISO 8601.'],
                        ],
                        'required' => [
                            'project', 'event_id', 'error_uuid', 'name', 'email', 'message', 'url', 'source', 'received',
                        ],
                        'additionalProperties' => false,
                    ],
                ],
                'total' => ['type' => 'integer', 'minimum' => 0, 'description' => 'All the matching entries.'],
            ],
            'required' => ['feedback', 'total'],
            'additionalProperties' => false,
        ];
    }

    #[\Override]
    public function annotations(): array
    {
        return ['readOnlyHint' => true, 'openWorldHint' => false];
    }

    /**
     * @return array{feedback: list<array<non-empty-string, string|null>>, total: int<0, max>}
     */
    #[\Override]
    public function call(array $arguments): array
    {
        $project = \trim(Arguments::strings($arguments, 'project')[0] ?? '');
        $eventId = Arguments::strings($arguments, 'event_id')[0] ?? '';
        $limit = Arguments::integer($arguments, 'limit', 1, self::MAX_LIMIT) ?? self::DEFAULT_LIMIT;

        $matches = \array_values(\array_filter(
            \array_reverse($this->store->feedback()),
            static fn(Feedback $feedback): bool => ($project === '' || $feedback->project === $project)
                && (\trim($eventId) === '' || $feedback->isAbout($eventId)),
        ));

        return [
            'feedback' => \array_map(fn(Feedback $feedback): array => [
                'project' => $feedback->project,
                'event_id' => $feedback->eventId,
                'error_uuid' => $this->errorUuid($feedback),
                'name' => $feedback->name,
                'email' => $feedback->email,
                'message' => $feedback->message,
                'url' => $feedback->url,
                'source' => $feedback->source,
                'received' => Time::format($feedback->time),
            ], \array_slice($matches, 0, $limit)),
            'total' => \count($matches),
        ];
    }

    private function errorUuid(Feedback $feedback): ?string
    {
        if ($feedback->eventId === '') {
            return null;
        }

        foreach ($this->events as $event) {
            /** @var mixed $id */
            $id = $event->type === 'sentry' && \is_array($event->payload) ? $event->payload['event_id'] ?? null : null;
            if (\is_string($id) && $feedback->isAbout($id)) {
                return $event->uuid;
            }
        }

        return null;
    }
}
