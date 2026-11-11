<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Profiler;

/**
 * Too many profile builds wait for the {@see BuildLock}.
 *
 * @internal
 */
final class BuildQueueFull extends \RuntimeException {}
