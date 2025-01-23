<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender;

use Buggregator\Trap\Sender;

/**
 * @internal
 */
final class SenderRegistry
{
    /** @var array<non-empty-string, Sender> */
    private array $senders = [];

    /** @var array<non-empty-string, \Closure(): Sender> */
    private array $factory = [];

    /**
     * @param non-empty-string $name
     * @param callable(): Sender $factory
     */
    public function register(string $name, callable $factory): void
    {
        $this->factory[$name] = $factory(...);
    }

    /**
     * @param non-empty-string[] $types
     * @return Sender[]
     */
    public function getSenders(array $types): array
    {
        $senders = [];
        foreach ($types as $type) {
            $senders[] = $this->senders[$type] ?? ($this->factory[$type] ?? throw new \InvalidArgumentException(
                "Unknown sender type `{$type}`.",
            ))();
        }

        return $senders;
    }
}
