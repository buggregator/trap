<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Event;

/**
 * A payload too large for the event lists: the lists and the live feed get its preview, the page of the event
 * the whole payload, as buggregator/server sends them.
 *
 * @internal
 */
interface Previewable extends \JsonSerializable
{
    /**
     * @return array<non-empty-string, mixed> What the feed card shows.
     */
    public function preview(): array;
}
