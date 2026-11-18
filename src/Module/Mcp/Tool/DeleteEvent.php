<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Mcp\Tool;

use Buggregator\Trap\Module\Mcp\Tool;
use Buggregator\Trap\Server\Event\EventStore;

/**
 * Deletes events by ID from the buffer the web UI shares: the UI shows them no more after a reload.
 *
 * The store keeps no trace of a deleted event, so an ID deleted before is as unknown as a wrong one. A call that finds
 * none of its IDs fails; one that finds some deletes them and lists the others as `not_found`.
 *
 * @internal
 */
final class DeleteEvent implements Tool
{
    public const MAX_IDS = 200;

    public function __construct(
        private readonly EventStore $store,
    ) {}

    #[\Override]
    public function name(): string
    {
        return 'delete_event';
    }

    #[\Override]
    public function title(): string
    {
        return 'Delete event';
    }

    #[\Override]
    public function description(): string
    {
        return 'Deletes caught events by ID: one with `id`, or up to ' . self::MAX_IDS . ' with `ids`. The Trap '
            . 'web UI drops them on its next load. Take the IDs from `list_events`. An ID of no stored event, '
            . 'deleted before too, is listed in `not_found`; when none of the IDs is found, the call fails. '
            . 'To delete events by type, time, text or fields, use `clear_events`.';
    }

    #[\Override]
    public function inputSchema(): array
    {
        $id = ['type' => 'string', 'minLength' => 1];

        return [
            'type' => 'object',
            'properties' => [
                'id' => [...$id, 'description' => 'The ID of the event, from `list_events`.'],
                'ids' => [
                    'type' => 'array',
                    'items' => $id,
                    'minItems' => 1,
                    'maxItems' => self::MAX_IDS,
                    'description' => 'The IDs of several events, instead of `id`.',
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
                'deleted' => ['type' => 'integer', 'minimum' => 1, 'description' => 'The number of deleted events.'],
                'not_found' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Present when some of the IDs are of no stored event: deleted before, pushed '
                        . 'out by newer events, or wrong.',
                ],
            ],
            'required' => ['deleted'],
            'additionalProperties' => false,
        ];
    }

    /**
     * A repeated call deletes nothing more: it fails, as its IDs are unknown then.
     */
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
     * @return array{deleted: positive-int, not_found?: non-empty-list<non-empty-string>}
     */
    #[\Override]
    public function call(array $arguments): array
    {
        $ids = self::ids($arguments);
        // Checked before the first deletion: a call that fails deletes nothing
        $found = \array_values(\array_filter($ids, fn(string $id): bool => $this->store->record($id) !== null));
        $found === [] and throw new \InvalidArgumentException(\sprintf(
            '%s: %s deleted before or pushed out by newer events. Call `list_events` for the current IDs.',
            \count($ids) === 1 ? "No event with ID `$ids[0]`" : 'None of the IDs is of a stored event',
            \count($ids) === 1 ? 'it may have been' : 'they may have been',
        ));

        foreach ($found as $id) {
            $this->store->delete($id);
        }

        $missing = \array_values(\array_diff($ids, $found));

        return $missing === []
            ? ['deleted' => \count($found)]
            : ['deleted' => \count($found), 'not_found' => $missing];
    }

    /**
     * @param array<array-key, mixed> $arguments
     *
     * @return non-empty-list<non-empty-string> Without repeats.
     *
     * @throws \InvalidArgumentException
     */
    private static function ids(array $arguments): array
    {
        /** @var mixed $id */
        $id = $arguments['id'] ?? null;
        /** @var mixed $ids */
        $ids = $arguments['ids'] ?? null;
        ($id === null) !== ($ids === null) or throw new \InvalidArgumentException(
            'Pass either `id`, the ID of one event, or `ids`, a list of them.',
        );

        if ($id !== null) {
            \is_string($id) && $id !== '' or throw new \InvalidArgumentException('`id` must be a non-empty string.');

            return [$id];
        }

        \is_array($ids) && $ids !== [] && \array_is_list($ids) && \count($ids) <= self::MAX_IDS && \array_filter(
            $ids,
            static fn(mixed $item): bool => \is_string($item) && $item !== '',
        ) === $ids or throw new \InvalidArgumentException(\sprintf(
            '`ids` must be a list of 1 to %d non-empty strings.',
            self::MAX_IDS,
        ));
        /** @var non-empty-list<non-empty-string> $ids */

        return \array_values(\array_unique($ids));
    }
}
