<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Mcp\View;

use Buggregator\Trap\Server\Event\Event;

/**
 * The fields of an event the agent filters by with `where`, such as the level of a log record or the recipients
 * of a mail. An {@see EventView} of a module implements it to give the fields of its events; the providers are
 * asked in the order of the views, and the first answer that is not null wins.
 *
 * @internal
 */
interface EventFields
{
    /**
     * Shown to the agent in the tool descriptions.
     *
     * @return array<non-empty-string, list<non-empty-string>> Event type => names of the fields it may have.
     */
    public function fieldNames(): array;

    /**
     * @return array<non-empty-string, scalar|list<scalar>>|null Field name => value; a list matches when any of its
     *         values does. Null to leave the event to the next provider.
     */
    public function fields(Event $event): ?array;
}
