<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Application\Config\Internal\Stub;

use Buggregator\Trap\Application\Config\Internal\Attribute\ConfigAttribute;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class UnsupportedAttribute implements ConfigAttribute {}
