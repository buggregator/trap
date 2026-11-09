<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Application\Module\Stub;

use Buggregator\Trap\Traffic\ProvidesDispatchers;

final class Dispatching implements ProvidesDispatchers
{
    public function dispatchers(): array
    {
        return [];
    }
}
