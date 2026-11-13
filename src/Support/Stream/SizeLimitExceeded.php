<?php

declare(strict_types=1);

namespace Buggregator\Trap\Support\Stream;

/**
 * Decoded data outgrew the limit set for it.
 *
 * @internal
 * @psalm-internal Buggregator\Trap
 */
final class SizeLimitExceeded extends \LengthException {}
