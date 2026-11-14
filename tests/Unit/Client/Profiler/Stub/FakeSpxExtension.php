<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Client\Profiler\Stub;

use Buggregator\Trap\Client\Profiler\Internal\SpxConfig;
use Buggregator\Trap\Client\Profiler\Internal\SpxExtension;

/**
 * ext-spx that writes a canned report into a directory, or makes none, as SPX does when it doesn't start.
 */
final class FakeSpxExtension extends SpxExtension
{
    public const KEY = 'spx-full-20250518_120000-app-42-1804289383';

    /** @var list<string> */
    public array $calls = [];

    /**
     * @param string $stub Path to the report without the extension: `.json` and the plain `.txt` trace.
     * @param bool $reports Whether stopping gives a report.
     */
    public function __construct(
        private readonly string $dir,
        private readonly string $stub,
        private readonly bool $reports = true,
    ) {}

    #[\Override]
    public function isLoaded(): bool
    {
        return true;
    }

    #[\Override]
    public function config(): SpxConfig
    {
        $settings = ['SPX_ENABLED' => '1', 'SPX_AUTO_START' => '0', 'SPX_REPORT' => 'full'];

        return new SpxConfig(true, static fn(string $name): ?string => $settings[$name] ?? null);
    }

    #[\Override]
    public function dataDir(): string
    {
        return $this->dir;
    }

    #[\Override]
    public function start(): void
    {
        $this->calls[] = 'start';
    }

    #[\Override]
    public function stop(): ?string
    {
        $this->calls[] = 'stop';
        if (!$this->reports) {
            return null;
        }

        \file_put_contents($this->dir . '/' . self::KEY . '.txt.gz', \gzencode((string) \file_get_contents($this->stub . '.txt'), 1));
        \copy($this->stub . '.json', $this->dir . '/' . self::KEY . '.json');
        return self::KEY;
    }
}
