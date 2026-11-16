<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Mcp\Tool;

use Buggregator\Trap\Module\Mcp\Tool;
use Buggregator\Trap\Server\Application\Module\Modules;
use Buggregator\Trap\Server\Event\EventStore;

/**
 * Deletes events from the buffer the web UI shares: the UI shows them no more after a reload.
 *
 * @internal
 */
final class ClearEvents implements Tool
{
    /** @var list<non-empty-string> */
    private readonly array $types;

    public function __construct(
        private readonly EventStore $store,
        Modules $modules,
    ) {
        $this->types = EventFilter::knownTypes($modules);
    }

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
        return 'Deletes the caught events, all of them or those of a type or a project. The Trap web UI drops '
            . 'them on its next load: a page already open keeps showing them. Returns the number of deleted '
            . 'events. Not needed to see only new events: pass a cursor to `list_events` instead.';
    }

    #[\Override]
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => EventFilter::schema($this->types),
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
     * @return array{removed: int<0, max>}
     */
    #[\Override]
    public function call(array $arguments): array
    {
        $filter = EventFilter::fromArguments($arguments, $this->types);
        if ($filter->isEmpty()) {
            $removed = \count($this->store);
            $this->store->clear();

            return ['removed' => $removed];
        }

        $uuids = [];
        foreach ($this->store as $uuid => $event) {
            $filter->matches($event) and $uuids[] = $uuid;
        }

        foreach ($uuids as $uuid) {
            $this->store->delete($uuid);
        }

        return ['removed' => \count($uuids)];
    }
}
