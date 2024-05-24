<?php

declare(strict_types=1);

namespace Buggregator\Trap\Support\Caster;

use Buggregator\Trap\Support\Tick;
use Symfony\Component\VarDumper\Cloner\Stub;

/**
 * @internal
 */
final class TickerCaster
{
    public static function cast(Tick $tick, array $a, Stub $stub, bool $isNested): mixed
    {
        $stub->type = $stub::TYPE_REF;
        $stub->class = $tick->__toString();
        $stub->attr = $tick->line;

        return [];
    }
}
