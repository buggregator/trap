<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Mcp;

/**
 * A tool whose call may wait, suspending the fiber of its request: it fails in a JSON-RPC batch,
 * whose calls run one after another.
 *
 * @internal
 */
interface WaitingTool extends Tool {}
