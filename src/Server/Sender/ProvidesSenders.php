<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Sender;

use Buggregator\Trap\Server\Application\Module\Module;
use Buggregator\Trap\Server\Sender;

/**
 * A channel module: its senders take the caught frames somewhere, chosen with the `--sender` option
 * of the `run` command.
 *
 * @internal
 */
interface ProvidesSenders extends Module
{
    /**
     * A sender is created only when it is chosen, as a container singleton.
     *
     * A sender may also be {@see \Buggregator\Trap\Server\Processable} to run in the main loop,
     * and {@see ServesHttp} to receive HTTP requests.
     *
     * @return array<non-empty-string, class-string<Sender>> Sender classes by their `--sender` name.
     */
    public function senders(): array;
}
