<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Proto\Stub;

use Buggregator\Trap\Module\VarDumper\Frame as VarDumperFrame;
use Buggregator\Trap\Proto\ProvidesFrameTypes;

final class ProvidingFrameTypes implements ProvidesFrameTypes
{
    public function frameTypes(): array
    {
        return ['dump' => VarDumperFrame::class];
    }
}
