<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Profiler\Config;

use Buggregator\Trap\Module\Profiler\FilesObserver\SPX as Converter;
use Buggregator\Trap\Server\Application\Config\Internal\Attribute\Env;
use Buggregator\Trap\Server\Application\Config\Internal\Attribute\InflectableConfig;
use Buggregator\Trap\Server\Application\Config\Internal\Attribute\PhpIni;
use Buggregator\Trap\Server\Config\Files\ObserverConfig;
use Buggregator\Trap\Server\Service\FilesObserver\FrameConverter;

/**
 * Observes the SPX data directory for full reports.
 *
 * SPX keeps the reports for its web UI, so the ones written before Trap started are skipped.
 * A report may stay unchanged for an hour before it is dropped: SPX buffers thousands of events before it writes
 * them, so the trace of a slow or idle run doesn't grow for long, while the trace of a killed run never ends.
 *
 * @internal
 */
#[InflectableConfig]
final class SPX extends ObserverConfig
{
    /** @var non-empty-string|null Path to SPX files */
    #[Env('TRAP_SPX_PATH')]
    #[PhpIni('spx.data_dir')]
    public ?string $path = null;

    /** @var class-string<FrameConverter>|null */
    public ?string $converterClass = Converter::class;

    public bool $skipOldFiles = true;
    public ?float $abandonAfter = 3600.0;
}
