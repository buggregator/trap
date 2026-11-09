<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Profiler\Config;

use Buggregator\Trap\Application\Config\Internal\Attribute\Env;
use Buggregator\Trap\Application\Config\Internal\Attribute\InflectableConfig;
use Buggregator\Trap\Application\Config\Internal\Attribute\PhpIni;
use Buggregator\Trap\Config\Server\Files\ObserverConfig;
use Buggregator\Trap\Module\Profiler\FilesObserver\XDebug as Converter;
use Buggregator\Trap\Service\FilesObserver\FrameConverter;

/**
 * Observes the explicit {@see $path}, or else {@see $outputDir} while the Xdebug profiler is on.
 *
 * @internal
 */
#[InflectableConfig]
final class XDebug extends ObserverConfig
{
    /** @var non-empty-string|null Path to XDebug files */
    #[Env('TRAP_XDEBUG_PATH')]
    public ?string $path = null;

    /**
     * @var string|null Comma-separated Xdebug modes.
     *      Xdebug takes the `XDEBUG_MODE` environment variable over the `xdebug.mode` setting.
     */
    #[Env('XDEBUG_MODE')]
    #[PhpIni('xdebug.mode')]
    public ?string $mode = null;

    /**
     * @var non-empty-string|null Where the Xdebug profiler writes its files.
     *      Xdebug always defines it, the system temp directory by default.
     */
    #[PhpIni('xdebug.output_dir')]
    public ?string $outputDir = null;

    /** @var class-string<FrameConverter>|null */
    public ?string $converterClass = Converter::class;

    /**
     * Resolved here, not on hydration: the config inflector only assigns properties.
     */
    #[\Override]
    public function isValid(): bool
    {
        $this->path ??= $this->isProfilerOn() ? $this->outputDir : null;

        return parent::isValid();
    }

    private function isProfilerOn(): bool
    {
        return $this->mode !== null
            && \in_array('profile', \array_map(\trim(...), \explode(',', $this->mode)), true);
    }
}
