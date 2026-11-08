<?php

declare(strict_types=1);

namespace Buggregator\Trap\Config\Server\Files;

use Buggregator\Trap\Application\Config\Internal\Attribute\Env;
use Buggregator\Trap\Application\Config\Internal\Attribute\InflectableConfig;
use Buggregator\Trap\Application\Config\Internal\Attribute\PhpIni;
use Buggregator\Trap\Service\FilesObserver\Converter\XDebug as Converter;
use Buggregator\Trap\Service\FilesObserver\FrameConverter;

/**
 * @internal
 */
#[InflectableConfig]
final class XDebug extends ObserverConfig
{
    /** @var non-empty-string|null Path to XDebug files */
    #[Env('TRAP_XDEBUG_PATH')]
    #[PhpIni('xdebug.output_dir')]
    public ?string $path = null;

    /** @var class-string<FrameConverter>|null */
    public ?string $converterClass = Converter::class;
}
