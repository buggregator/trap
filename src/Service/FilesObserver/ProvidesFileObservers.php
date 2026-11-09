<?php

declare(strict_types=1);

namespace Buggregator\Trap\Service\FilesObserver;

use Buggregator\Trap\Application\Module\Module;
use Buggregator\Trap\Config\Server\Files\ObserverConfig;

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
