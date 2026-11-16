<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Mcp\Tool;

use Buggregator\Trap\Server\Application\Module\Modules;
use Buggregator\Trap\Server\Event\Event;
use Buggregator\Trap\Server\Event\ProvidesEvents;

/**
 * The `type` and `project` arguments of the event tools.
 *
 * @internal
 */
final class EventFilter
{
    /**
     * @param list<non-empty-string> $types Any type when empty.
     * @param string|null $project Project key; any project when null.
     */
    private function __construct(
        public readonly array $types,
        public readonly ?string $project,
    ) {}

    /**
     * @return list<non-empty-string> Event types the modules declare, in the module list order.
     */
    public static function knownTypes(Modules $modules): array
    {
        $types = [];
        foreach ($modules->implementing(ProvidesEvents::class) as $module) {
            $types = [...$types, ...$module->eventTypes()];
        }

        return \array_values(\array_unique($types));
    }

    /**
     * @param list<non-empty-string> $known
     *
     * @return array<non-empty-string, array<non-empty-string, mixed>> Properties of the input schema.
     */
    public static function schema(array $known): array
    {
        $type = ['type' => 'string'];
        $known === [] or $type['enum'] = $known;

        return [
            'type' => [
                'description' => 'Only the events of this type or of any of these types.',
                'anyOf' => [$type, ['type' => 'array', 'items' => $type, 'minItems' => 1]],
            ],
            'project' => [
                'type' => 'string',
                'description' => \sprintf(
                    'Only the events of this project; events sent without a project belong to `%s`. All projects when omitted.',
                    Event::DEFAULT_PROJECT,
                ),
            ],
        ];
    }

    /**
     * @param array<array-key, mixed> $arguments
     * @param list<non-empty-string> $known
     *
     * @throws \InvalidArgumentException
     */
    public static function fromArguments(array $arguments, array $known): self
    {
        /** @var mixed $type */
        $type = $arguments['type'] ?? [];
        $types = \is_string($type) ? [$type] : $type;
        \is_array($types) && \array_is_list($types)
            or throw new \InvalidArgumentException('`type` must be a string or a list of strings.');
        foreach ($types as $value) {
            \is_string($value) && \in_array($value, $known, true) or throw new \InvalidArgumentException(\sprintf(
                'Unknown event type %s; known types: %s.',
                \is_string($value) ? "`$value`" : \get_debug_type($value),
                \implode(', ', $known),
            ));
        }
        /** @var mixed $project */
        $project = $arguments['project'] ?? null;
        $project === null || \is_string($project)
            or throw new \InvalidArgumentException('`project` must be a string.');

        return new self(\array_values(\array_intersect($known, $types)), $project === '' ? Event::DEFAULT_PROJECT : $project);
    }

    public function isEmpty(): bool
    {
        return $this->types === [] && $this->project === null;
    }

    public function matches(Event $event): bool
    {
        return ($this->types === [] || \in_array($event->type, $this->types, true))
            && ($this->project === null || $event->projectKey() === $this->project);
    }
}
