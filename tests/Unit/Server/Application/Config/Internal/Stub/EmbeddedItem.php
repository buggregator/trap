<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Server\Application\Config\Internal\Stub;

use Buggregator\Trap\Server\Application\Config\Internal\Attribute\InflectableConfig;
use Buggregator\Trap\Server\Application\Config\Internal\Attribute\XPath;

#[InflectableConfig]
final class EmbeddedItem
{
    #[XPath('@name')]
    public string $name = '';
}
