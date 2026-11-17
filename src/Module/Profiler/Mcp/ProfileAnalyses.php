<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Profiler\Mcp;

use Buggregator\Trap\Module\Profiler\Frame\Payload;
use Buggregator\Trap\Server\Event\EventStore;
use Buggregator\Trap\Support\TimeSlice;

/**
 * The analyses of the profiles the agent has asked about lately, by the event IDs and versions.
 *
 * @internal
 */
final class ProfileAnalyses
{
    /** An analysis holds about as much as its profile: only a few are kept. */
    private const SIZE = 4;

    /** @var array<non-empty-string, ProfileAnalysis> By `uuid:seq` keys, the least recently used first */
    private array $analyses = [];

    /**
     * @param (\Closure(): TimeSlice)|null $timeSlices See {@see ProfileAnalysis::__construct()}.
     * @param (\Closure(): int<1, max>)|null $nodeBudget See {@see ProfileAnalysis::__construct()}.
     */
    public function __construct(
        private readonly EventStore $store,
        private readonly ?\Closure $timeSlices = null,
        private readonly ?\Closure $nodeBudget = null,
    ) {}

    /**
     * @return array<non-empty-string, mixed> The property of the input schema.
     */
    public static function idSchema(): array
    {
        return [
            'type' => 'string',
            'description' => 'The ID of a `profiler` event from `list_events` or `wait_for_events`.',
        ];
    }

    /**
     * The analysis of the profile the `id` argument names.
     *
     * @param array<array-key, mixed> $arguments
     *
     * @throws \InvalidArgumentException When the event is missing or not a profile.
     */
    public function get(array $arguments): ProfileAnalysis
    {
        /** @var mixed $id */
        $id = $arguments['id'] ?? null;
        \is_string($id) && $id !== '' or throw new \InvalidArgumentException('`id` must be a non-empty string.');
        $record = $this->store->record($id) ?? throw new \InvalidArgumentException(
            "No event with ID `$id`: it may have been deleted. Call `list_events` with `type: profiler` for the profiles.",
        );
        $payload = $record->event->payload;
        $payload instanceof Payload or throw new \InvalidArgumentException(\sprintf(
            'Event `%s` is a `%s` event, not a profile. Call `list_events` with `type: profiler` for the profiles.',
            $id,
            $record->event->type,
        ));

        $key = "$id:$record->seq";
        $analysis = $this->analyses[$key] ?? new ProfileAnalysis($id, $payload, $this->timeSlices, $this->nodeBudget);
        unset($this->analyses[$key]);
        $this->analyses[$key] = $analysis;
        \count($this->analyses) > self::SIZE and \array_shift($this->analyses);

        return $analysis;
    }
}
