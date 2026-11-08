<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Application\Config\Internal\Stub;

use Buggregator\Trap\Application\Config\Internal\Attribute\InflectableConfig;
use Buggregator\Trap\Application\Config\Internal\Attribute\XPath;

#[InflectableConfig]
final class EmbeddedItem
{
    #[XPath('@name')]
    public string $name = '';
}
