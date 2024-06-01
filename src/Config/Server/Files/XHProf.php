<?php

declare(strict_types=1);

namespace Buggregator\Trap\Config\Server\Files;

use Buggregator\Trap\Service\Config\Env;
use Buggregator\Trap\Service\Config\PhpIni;
use Buggregator\Trap\Service\FilesObserver\Converter\XHProf as Converter;
use Buggregator\Trap\Service\FilesObserver\FrameConverter;

/**
 * @internal
 */
final class XHProf extends ObserverConfig
{
    /**
     * @var int<0, 3> Edges sorting algorithm
     *      Where:
     *        0 - Deep-first
     *        1 - Deep-first with sorting by WT
     *        2 - Level-by-level
     *        3 - Level-by-level with sorting by WT
     */
    #[Env('TRAP_XHPROF_SORT')]
    public int $algorithm = 3;

    /** @var non-empty-string|null Path to XHProf files */
    #[Env('TRAP_XHPROF_PATH')]
    #[PhpIni('xhprof.output_dir')]
    public ?string $path = null;

    /** @var class-string<FrameConverter>|null */
    public ?string $converterClass = Converter::class;
}
