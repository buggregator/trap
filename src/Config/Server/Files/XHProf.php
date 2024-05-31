<?php

declare(strict_types=1);

namespace Buggregator\Trap\Config\Server\Files;

use Buggregator\Trap\Service\Config\PhpIni;
use Buggregator\Trap\Service\FilesObserver\Converter\XHProf as Converter;

/**
 * @internal
 */
final class XHProf extends ObserverConfig
{
    /** @var non-empty-string|null Path to XHProf files */
    #[PhpIni('xhprof.output_dir')]
    public ?string $path = null;

    public ?string $converterClass = Converter::class;
}
