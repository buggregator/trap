<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Profiler\Config;

use Buggregator\Trap\Application\Config\Internal\Attribute\Env;
use Buggregator\Trap\Application\Config\Internal\Attribute\InflectableConfig;
use Buggregator\Trap\Application\Config\Internal\Attribute\PhpIni;
use Buggregator\Trap\Config\Server\Files\ObserverConfig;
use Buggregator\Trap\Module\Profiler\FilesObserver\XHProf as Converter;
use Buggregator\Trap\Service\FilesObserver\FrameConverter;

/**
 * @internal
 */
#[InflectableConfig]
final class XHProf extends ObserverConfig
{
    /** @var non-empty-string|null Path to XHProf files */
    #[Env('TRAP_XHPROF_PATH')]
    #[PhpIni('xhprof.output_dir')]
    public ?string $path = null;

    /** @var class-string<FrameConverter>|null */
    public ?string $converterClass = Converter::class;
}
