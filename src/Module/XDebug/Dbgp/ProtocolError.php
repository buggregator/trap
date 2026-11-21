<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\XDebug\Dbgp;

/**
 * The peer broke the DBGp framing: the connection can't go on.
 *
 * @internal
 */
final class ProtocolError extends \RuntimeException {}
