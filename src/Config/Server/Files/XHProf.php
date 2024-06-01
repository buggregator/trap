<?php

declare(strict_types=1);

namespace Buggregator\Trap\Config\Server\Files;

use Buggregator\Trap\Service\Config\Env;
use Buggregator\Trap\Service\Config\PhpIni;
use Buggregator\Trap\Service\FilesObserver\Converter\XHProf as Converter;

/**
 * @internal
 */
final class XHProf extends ObserverConfig
{
    /** @var int<0, 3> Edges sorting algorithm */
    #[Env('TRAP_XHPROF_SORT')]
    public int $algorithm = 1;

    /** @var non-empty-string|null Path to XHProf files */
    #[Env('TRAP_XHPROF_PATH')]
    #[PhpIni('xhprof.output_dir')]
    public ?string $path = null;

    public ?string $converterClass = Converter::class;
}
