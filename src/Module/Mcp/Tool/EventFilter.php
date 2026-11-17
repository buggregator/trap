<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Mcp\Tool;

use Buggregator\Trap\Module\Mcp\View\EventViews;
use Buggregator\Trap\Module\Mcp\View\TextCache;
use Buggregator\Trap\Server\Event\Record;

/**
 * Which events a tool takes, read by {@see EventFilters} from the arguments. The cheap conditions are checked
 * first, and the text view is rendered only for an event whose summary does not contain the searched text.
 *
 * @internal
 */
final class EventFilter
{
    /**
     * @param list<non-empty-string> $types Any type when empty.
     * @param list<non-empty-string> $projects Project keys; any project when empty.
     * @param float|null $from Unix time in seconds of the earliest caught event, inclusive.
     * @param float|null $to Unix time in seconds of the latest caught event, inclusive.
     * @param array<non-empty-string, list<\Closure(scalar): bool>> $where Field name => checks of a value of the
     *        field, any of which must pass.
     */
    public function __construct(
        private readonly EventViews $views,
        private readonly TextCache $texts,
        public readonly array $types = [],
        public readonly array $projects = [],
        public readonly ?float $from = null,
        public readonly ?float $to = null,
        private readonly ?TextSearch $search = null,
        private readonly array $where = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->types === []
            && $this->projects === []
            && $this->from === null
            && $this->to === null
            && $this->search === null
            && $this->where === [];
    }

    /**
     * @throws \InvalidArgumentException When the regular expression of the search fails on the event.
     */
    public function matches(Record $record): bool
    {
        $event = $record->event;

        return ($this->types === [] || \in_array($event->type, $this->types, true))
            && ($this->projects === [] || \in_array($event->projectKey(), $this->projects, true))
            && ($this->from === null || $event->timestamp >= $this->from)
            && ($this->to === null || $event->timestamp <= $this->to)
            && $this->matchesFields($record)
            && $this->matchesText($record);
    }

    private function matchesFields(Record $record): bool
    {
        if ($this->where === []) {
            return true;
        }

        $fields = $this->views->fields($record->event);
        foreach ($this->where as $name => $checks) {
            $values = $fields[$name] ?? [];
            $passed = false;
            foreach (\is_array($values) ? $values : [$values] as $value) {
                foreach ($checks as $check) {
                    if ($check($value)) {
                        $passed = true;
                        break 2;
                    }
                }
            }

            if (!$passed) {
                return false;
            }
        }

        return true;
    }

    private function matchesText(Record $record): bool
    {
        return $this->search === null
            || $this->search->matches($this->views->summary($record->event))
            || $this->search->matches($this->texts->text($record));
    }
}
