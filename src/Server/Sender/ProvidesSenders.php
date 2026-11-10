<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Sender;

use Buggregator\Trap\Server\Application\Module\Module;
use Buggregator\Trap\Server\Sender;

/**
 * A module with its own senders, chosen with the `--sender` option of the `run` command.
 *
 * @internal
 */
interface ProvidesSenders extends Module
{
    /**
     * A sender is created only when it is chosen.
     *
     * @return array<non-empty-string, class-string<Sender>> Sender classes by their `--sender` name.
     */
    public function senders(): array;
}
