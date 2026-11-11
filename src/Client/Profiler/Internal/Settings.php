<?php

declare(strict_types=1);

namespace Buggregator\Trap\Client\Profiler\Internal;

use Buggregator\Trap\Client\Profiler\Profile;

/**
 * What a {@see \Buggregator\Trap\Client\Profiler\Profiling} is configured with.
 *
 * @internal
 */
final class Settings
{
    public ?string $name = null;

    /** @var array<non-empty-string, string> */
    public array $tags = [];

    /** @var bool|\Closure(): mixed */
    public bool|\Closure $if = true;

    public float $sample = 1.0;

    /** @var list<\Closure(Profile): void> */
    public array $destinations = [];

    /**
     * @param Driver|null $requested The driver passed by the user.
     * @param Driver|null $driver The driver to use: the requested one or the first available.
     */
    public function __construct(
        public readonly ?Driver $requested,
        public readonly ?Driver $driver,
    ) {}

    public function shouldProfile(): bool
    {
        $if = $this->if instanceof \Closure ? (bool) ($this->if)() : $this->if;

        return $if && ($this->sample >= 1.0 || \mt_rand() / \mt_getrandmax() < $this->sample);
    }

    /**
     * Runs every destination, or sends to Trap when none is configured.
     *
     * @param bool $quiet Drop the destination errors instead of rethrowing the first one.
     */
    public function deliver(Profile $profile, bool $quiet): void
    {
        $destinations = $this->destinations === []
            ? [static function (Profile $profile): void {
                TrapSender::create()->send($profile);
            }]
            : $this->destinations;

        $error = null;
        foreach ($destinations as $destination) {
            try {
                $destination($profile);
            } catch (\Throwable $e) {
                $error ??= $e;
            }
        }

        $error === null || $quiet or throw $error;
    }
}
