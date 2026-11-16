<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Sender;

use Buggregator\Trap\Server\Event\EventStore;
use Buggregator\Trap\Server\Event\StoreListener;

/**
 * A sender that works with events rather than frames, as the web UI does.
 *
 * With such a sender chosen, the application maps each caught frame to an event once and keeps it in
 * the {@see EventStore} before the senders get the frames; without one, frames are not mapped at all.
 * The sender reads the store, a container singleton, or follows it with a {@see StoreListener}.
 *
 * @internal
 */
interface ConsumesEvents {}
