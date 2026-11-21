<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\XDebugTrace\Config;

use Buggregator\Trap\Module\XDebugTrace\FilesObserver\TraceFiles;
use Buggregator\Trap\Server\Application\Config\Internal\Attribute\Env;
use Buggregator\Trap\Server\Application\Config\Internal\Attribute\InflectableConfig;
use Buggregator\Trap\Server\Application\Config\Internal\Attribute\PhpIni;
use Buggregator\Trap\Server\Config\Files\ObserverConfig;
use Buggregator\Trap\Server\Service\FilesObserver\FrameConverter;

/**
 * Observes the explicit {@see $path}, or else {@see $outputDir} while Xdebug tracing is on.
 *
 * @internal
 */
#[InflectableConfig]
final class TraceDirectory extends ObserverConfig
{
    /** @var non-empty-string|null Path to Xdebug trace files */
    #[Env('TRAP_XDEBUG_TRACE_PATH')]
    public ?string $path = null;

    /**
     * @var string|null Comma-separated Xdebug modes.
     *      Xdebug takes the `XDEBUG_MODE` environment variable over the `xdebug.mode` setting.
     */
    #[Env('XDEBUG_MODE')]
    #[PhpIni('xdebug.mode')]
    public ?string $mode = null;

    /** @var non-empty-string|null Where Xdebug writes its traces */
    #[PhpIni('xdebug.output_dir')]
    public ?string $outputDir = null;

    /** @var class-string<FrameConverter>|null */
    public ?string $converterClass = TraceFiles::class;

    /**
     * Traces of earlier runs stay in the directory: they would flood the feed on each start.
     */
    public bool $skipOldFiles = true;

    /**
     * Resolved here, not on hydration: the config inflector only assigns properties.
     */
    #[\Override]
    public function isValid(): bool
    {
        $this->path ??= $this->isTracingOn() ? $this->outputDir : null;

        return parent::isValid();
    }

    private function isTracingOn(): bool
    {
        return $this->mode !== null
            && \in_array('trace', \array_map(\trim(...), \explode(',', $this->mode)), true);
    }
}
