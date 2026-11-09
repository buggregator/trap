<?php

declare(strict_types=1);

namespace Buggregator\Trap\Application\Module;

/**
 * Manifest of a module: the class a module registers in {@see Modules}.
 *
 * A manifest implements the provider interfaces of the extension points it contributes to.
 * Provider methods return class names; the core creates the objects through the container.
 *
 * @internal
 */
interface Module {}
