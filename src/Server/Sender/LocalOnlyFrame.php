<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Sender;

/**
 * A frame that {@see RemoteSender} doesn't send: a receiver of protocol version 1 that doesn't know
 * its type rejects the whole package, the other frames in it included.
 *
 * @internal
 */
interface LocalOnlyFrame {}
