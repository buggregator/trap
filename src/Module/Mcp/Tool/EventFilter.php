<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Mcp\Tool;

use Buggregator\Trap\Module\Mcp\View\EventViews;
use Buggregator\Trap\Module\Mcp\View\TextCache;
use Buggregator\Trap\Server\Event\Record;

/**
 * Which events a tool takes, read by {@see EventFilterArguments} from the arguments. The cheap conditions are
 * checked first, and the text view is searched only for an event whose summary does not contain the searched text.
 *
 * @internal
 */
final class EventFilter
{
    /**
     * @param list<non-empty-string> $types Any type when empty.
     * @param list<non-empty-string> $projects Project keys; any project when empty.
     * @param array<non-empty-string, list<\Closure(scalar): bool>> $where Field name => checks of a value of the
     *        field, any of which must pass.
     */
    public function __construct(
        private readonly EventViews $views,
        private readonly TextCache $texts,
        public readonly array $types = [],
        public readonly array $projects = [],
        public readonly TimeRange $time = new TimeRange(),
        private readonly ?TextSearch $search = null,
        private readonly array $where = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->types === []
            && $this->projects === []
            && $this->time->isUnbounded()
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
            && $this->time->contains($event->timestamp)
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
            || $this->search->matches($this->texts->summary($record))
            || $this->search->matches($this->texts->searchable($record));
    }
}
