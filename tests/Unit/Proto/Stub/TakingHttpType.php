<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Proto\Stub;

use Buggregator\Trap\Module\VarDumper\Frame as VarDumperFrame;
use Buggregator\Trap\Proto\ProvidesFrameTypes;

final class TakingHttpType implements ProvidesFrameTypes
{
    public function frameTypes(): array
    {
        return ['http' => VarDumperFrame::class];
    }
}
