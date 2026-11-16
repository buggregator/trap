<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Mcp\Tool;

use Buggregator\Trap\Module\Mcp\Tool;
use Buggregator\Trap\Module\Mcp\View\EventViews;
use Buggregator\Trap\Server\Event\EventStore;

/**
 * An event as plain text: a header line, then the text of its {@see EventViews}.
 *
 * @internal
 */
final class GetEvent implements Tool
{
    public function __construct(
        private readonly EventStore $store,
        private readonly EventViews $views,
    ) {}

    #[\Override]
    public function name(): string
    {
        return 'get_event';
    }

    #[\Override]
    public function title(): string
    {
        return 'Get event';
    }

    #[\Override]
    public function description(): string
    {
        return 'Shows one caught event in full as plain text, as the Trap console prints it: the dumped value, '
            . 'the log record with its context, the exception with its stack trace, the mail with its headers and '
            . 'body, the HTTP request, or the profile with its top functions. Take the ID from `list_events`.';
    }

    #[\Override]
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => [
                    'type' => 'string',
                    'description' => 'The event ID from `list_events`.',
                ],
                'max_length' => MaxLength::schema(),
            ],
            'required' => ['id'],
            'additionalProperties' => false,
        ];
    }

    #[\Override]
    public function outputSchema(): ?array
    {
        return null;
    }

    #[\Override]
    public function annotations(): array
    {
        return ['readOnlyHint' => true, 'openWorldHint' => false];
    }

    #[\Override]
    public function call(array $arguments): string
    {
        /** @var mixed $id */
        $id = $arguments['id'] ?? null;
        \is_string($id) && $id !== '' or throw new \InvalidArgumentException('`id` must be a non-empty string.');
        $maxLength = MaxLength::fromArguments($arguments);
        $record = $this->store->record($id) ?? throw new \InvalidArgumentException(
            "No event with ID `$id`: it may have been deleted. Call `list_events` for the current IDs.",
        );

        $event = $record->event;
        $text = \sprintf(
            "%s event %s at %s, project %s\n%s\n\n%s",
            $event->type,
            $event->uuid,
            Time::format($event->timestamp),
            $event->projectKey(),
            $this->views->summary($event),
            $this->views->text($record),
        );

        return MaxLength::cut($text, $maxLength);
    }
}
