<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\XDebug\Link;

/**
 * One side of a debug session: the Xdebug engine or the IDE.
 *
 * @internal
 */
interface Link
{
    public function send(string $bytes): bool;

    /**
     * Takes the bytes received so far without waiting.
     *
     * @return string|null Null when the peer is gone and nothing is left.
     */
    public function receive(): ?string;

    public function close(): void;
}
