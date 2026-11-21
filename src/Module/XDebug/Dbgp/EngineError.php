<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\XDebug\Dbgp;

/**
 * An error Xdebug answered a command with, such as `300: can not get property`.
 *
 * @internal
 */
final class EngineError extends \RuntimeException
{
    public function __construct(
        public readonly string $engineCode,
        string $message,
    ) {
        parent::__construct("Xdebug error $engineCode: $message");
    }
}
