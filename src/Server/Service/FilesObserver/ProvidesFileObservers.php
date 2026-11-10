<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Service\FilesObserver;

use Buggregator\Trap\Server\Application\Module\Module;
use Buggregator\Trap\Server\Config\Files\ObserverConfig;

/**
 * A module that turns files written to a directory into frames.
 *
 * @internal
 */
interface ProvidesFileObservers extends Module
{
    /**
     * A config names both the directory and the {@see FrameConverter}; an inflectable config gets its
     * directory from the configuration sources when the container creates it.
     *
     * @return list<class-string<ObserverConfig>>
     */
    public function fileObserverConfigs(): array;
}
