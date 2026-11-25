<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Event;

/**
 * A payload that keeps the text the UI search finds its event by, made once when the event is: the fields
 * of the payload are not searched then.
 *
 * @internal
 */
interface Searchable
{
    /**
     * @return string In lower case, as `mb_strtolower()` makes it.
     */
    public function searchText(): string;
}
