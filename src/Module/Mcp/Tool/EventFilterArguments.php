<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Mcp\Tool;

use Buggregator\Trap\Module\Mcp\View\EventViews;
use Buggregator\Trap\Module\Mcp\View\TextCache;
use Buggregator\Trap\Server\Application\Module\Modules;
use Buggregator\Trap\Server\Event\Event;
use Buggregator\Trap\Server\Event\ProvidesEvents;

/**
 * The filter arguments the event tools share: `type`, `project`, `from`, `to`, `search`, `regex` and `where`.
 *
 * @internal
 */
final class EventFilterArguments
{
    /** @var list<non-empty-string> */
    public readonly array $types;

    public function __construct(
        Modules $modules,
        private readonly EventViews $views,
        private readonly TextCache $texts,
    ) {
        $types = [];
        foreach ($modules->implementing(ProvidesEvents::class) as $module) {
            $types = [...$types, ...$module->eventTypes()];
        }

        $this->types = \array_values(\array_unique($types));
    }

    /**
     * @return array<non-empty-string, array<non-empty-string, mixed>> Properties of the input schema.
     */
    public function schema(): array
    {
        $type = ['type' => 'string'];
        $this->types === [] or $type['enum'] = $this->types;
        $value = ['type' => ['string', 'number']];

        return [
            'type' => [
                'description' => 'Only the events of this type or of any of these types.',
                'anyOf' => [$type, ['type' => 'array', 'items' => $type, 'minItems' => 1]],
            ],
            'project' => [
                'description' => \sprintf(
                    'Only the events of this project or of any of these projects; events sent without a project '
                    . 'belong to `%s`. All projects when omitted.',
                    Event::DEFAULT_PROJECT,
                ),
                'anyOf' => [
                    ['type' => 'string'],
                    ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 1],
                ],
            ],
            'from' => Time::schema('Only the events caught at this time or later.'),
            'to' => Time::schema('Only the events caught at this time or earlier.'),
            ...TextSearch::schema(\sprintf(
                'events whose summary or text, as `get_event` shows it without its header line and only the '
                . 'first %d KB of the text,',
                TextCache::MAX_SEARCHED_BYTES / 1024,
            )),
            'where' => [
                'type' => 'object',
                'description' => 'Only the events whose fields match all of these, such as `{"level": "error"}` '
                    . 'or `{"path": "/api/orders*"}`. A string matches the whole value in any case, with `*` for '
                    . 'any characters; a number matches an equal number; a list of values matches any of them. '
                    . 'A field of a list, such as the mail recipients, matches when any of its values does. '
                    . 'An event without the field does not match, and a field no event type has fails the call. '
                    . 'The `from` field of a mail is its sender, unrelated to the `from` time. The fields by type: '
                    . $this->fieldsHelp() . '.',
                'minProperties' => 1,
                'additionalProperties' => [
                    'anyOf' => [$value, ['type' => 'array', 'items' => $value, 'minItems' => 1]],
                ],
            ],
        ];
    }

    /**
     * @return string The fields of each event type, as `monolog: level, channel; smtp: subject, to`.
     */
    public function fieldsHelp(): string
    {
        $help = [];
        foreach ($this->views->fieldNames() as $type => $names) {
            $help[] = $type . ': ' . \implode(', ', $names);
        }

        return \implode('; ', $help);
    }

    /**
     * @param array<array-key, mixed> $arguments
     * @param float|null $now Unix time in seconds the durations of `from` and `to` count back from;
     *        the current time when null.
     *
     * @throws \InvalidArgumentException
     */
    public function parse(array $arguments, ?float $now = null): EventFilter
    {
        $time = Time::range($arguments, $now ?? \microtime(true));
        $projects = \array_map(
            static fn(string $project): string => $project === '' ? Event::DEFAULT_PROJECT : $project,
            Arguments::strings($arguments, 'project'),
        );

        return new EventFilter(
            views: $this->views,
            texts: $this->texts,
            types: $this->eventTypes($arguments),
            projects: \array_values(\array_unique($projects)),
            time: $time,
            search: TextSearch::fromArguments($arguments),
            where: $this->where($arguments),
        );
    }

    /**
     * @return \Closure(scalar): bool
     */
    private static function check(int|float|string $expected): \Closure
    {
        if (!\is_string($expected)) {
            return static fn(bool|int|float|string $value): bool => !\is_bool($value)
                && \is_numeric($value)
                && (float) $value === (float) $expected;
        }

        $pattern = Wildcard::compile($expected);

        return static fn(bool|int|float|string $value): bool => $pattern->matches(match (true) {
            \is_bool($value) => $value ? 'true' : 'false',
            default => (string) $value,
        });
    }

    /**
     * @param array<array-key, mixed> $arguments
     *
     * @return array<non-empty-string, list<\Closure(scalar): bool>>
     *
     * @throws \InvalidArgumentException
     */
    private function where(array $arguments): array
    {
        /** @var mixed $where */
        $where = $arguments['where'] ?? null;
        if ($where === null) {
            return [];
        }

        // An empty JSON object is decoded as an empty array; it is rejected, as with no condition at all
        // `clear_events` would delete every event
        \is_array($where) && $where !== [] or throw new \InvalidArgumentException(
            '`where` must be an object of at least one field name and value, such as `{"level": "error"}`.',
        );

        $known = \array_values(\array_unique(\array_merge(...\array_values($this->views->fieldNames()))));
        $result = [];
        /** @var mixed $value */
        foreach ($where as $name => $value) {
            $name = (string) $name;
            $name === '' and throw new \InvalidArgumentException('A field name in `where` is empty.');
            // A JSON list is decoded with the keys 0, 1...: the same message fits it
            \in_array($name, $known, true) or throw new \InvalidArgumentException(\sprintf(
                'Unknown field `%s` in `where`: its keys must be field names, such as `{"level": "error"}`. '
                . 'Known fields: %s.',
                $name,
                \implode(', ', $known),
            ));
            $values = \is_array($value) ? $value : [$value];
            $values !== [] && \array_is_list($values) && \array_filter(
                $values,
                static fn(mixed $item): bool => \is_string($item) || \is_int($item) || \is_float($item),
            ) === $values or throw new \InvalidArgumentException(\sprintf(
                \array_filter($values, 'is_bool') === []
                    ? '`where.%s` must be a string, a number or a non-empty list of them.'
                    : '`where.%s` must be a string, a number or a non-empty list of them: no field holds a boolean.',
                $name,
            ));
            /** @var non-empty-list<string|int|float> $values */

            $result[$name] = \array_map(self::check(...), $values);
        }

        return $result;
    }

    /**
     * @param array<array-key, mixed> $arguments
     *
     * @return list<non-empty-string>
     *
     * @throws \InvalidArgumentException
     */
    private function eventTypes(array $arguments): array
    {
        $types = Arguments::strings($arguments, 'type');
        foreach ($types as $type) {
            \in_array($type, $this->types, true) or throw new \InvalidArgumentException(\sprintf(
                'Unknown event type `%s`; known types: %s.',
                $type,
                \implode(', ', $this->types),
            ));
        }

        return \array_values(\array_intersect($this->types, $types));
    }
}
