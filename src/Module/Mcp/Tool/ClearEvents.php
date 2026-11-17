<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Mcp\Tool;

use Buggregator\Trap\Module\Mcp\Tool;
use Buggregator\Trap\Server\Event\EventStore;

/**
 * Deletes events from the buffer the web UI shares: the UI shows them no more after a reload.
 *
 * @internal
 */
final class ClearEvents implements Tool
{
    public function __construct(
        private readonly EventStore $store,
        private readonly EventFilterArguments $filters,
        private readonly Scan $scan,
    ) {}

    #[\Override]
    public function name(): string
    {
        return 'clear_events';
    }

    #[\Override]
    public function title(): string
    {
        return 'Clear events';
    }

    #[\Override]
    public function description(): string
    {
        return 'Deletes the caught events, all of them or those that match the filter: the same filter as '
            . '`list_events` takes, by type, project, time, text and fields. The Trap web UI drops them on its '
            . 'next load: a page already open keeps showing them. Returns the number of deleted events. Not '
            . 'needed to see only new events: pass a cursor to `list_events` instead. ' . \sprintf(
                'A call stops checking events after %s seconds, as a text search over many large events may take '
                . 'longer: then it deletes the matching events found so far and returns `incomplete: true`; '
                . 'call again with the same filter to delete the rest.',
                \round($this->scan->budget, 3),
            );
    }

    #[\Override]
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => $this->filters->schema(),
            'additionalProperties' => false,
        ];
    }

    #[\Override]
    public function outputSchema(): ?array
    {
        return [
            'type' => 'object',
            'properties' => [
                'removed' => ['type' => 'integer', 'minimum' => 0, 'description' => 'The number of deleted events.'],
                'incomplete' => [
                    'type' => 'boolean',
                    'const' => true,
                    'description' => 'Present when the call ran out of time before checking all the events: '
                        . 'call again with the same filter to delete the rest.',
                ],
            ],
            'required' => ['removed'],
            'additionalProperties' => false,
        ];
    }

    #[\Override]
    public function annotations(): array
    {
        return [
            'readOnlyHint' => false,
            'destructiveHint' => true,
            'idempotentHint' => true,
            'openWorldHint' => false,
        ];
    }

    /**
     * @return array{removed: int<0, max>, incomplete?: true}
     */
    #[\Override]
    public function call(array $arguments): array
    {
        $filter = $this->filters->parse($arguments);
        if ($filter->isEmpty()) {
            $removed = \count($this->store);
            $this->store->clear();

            return ['removed' => $removed];
        }

        // Matched in full before the first deletion: a failing search deletes nothing
        $matched = [];
        $walk = $this->scan->walk($this->store, $this->store->changedSince(0));
        foreach ($walk as $record) {
            $filter->matches($record) and $matched[] = $record;
        }

        $removed = 0;
        foreach ($matched as $record) {
            // Changed while the scan let the server run: the new event was not matched
            if ($this->store->record($record->event->uuid)?->seq === $record->seq) {
                $this->store->delete($record->event->uuid);
                ++$removed;
            }
        }

        return $walk->getReturn() === null ? ['removed' => $removed] : ['removed' => $removed, 'incomplete' => true];
    }
}
