<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Traffic\Parser;

/**
 * A request the HTTP parser can't read. The message never quotes the request: it may carry a secret.
 *
 * @internal
 * @psalm-internal Buggregator\Trap
 */
final class InvalidRequest extends \InvalidArgumentException {}
