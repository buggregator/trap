<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Server\Application\Config\Internal\Stub;

use Buggregator\Trap\Server\Application\Config\Internal\Attribute\ConfigAttribute;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class UnsupportedAttribute implements ConfigAttribute {}
