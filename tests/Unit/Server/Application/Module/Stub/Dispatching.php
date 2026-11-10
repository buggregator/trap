<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Server\Application\Module\Stub;

use Buggregator\Trap\Server\Traffic\ProvidesDispatchers;

final class Dispatching implements ProvidesDispatchers
{
    public function dispatchers(): array
    {
        return [];
    }
}
