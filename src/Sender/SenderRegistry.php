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

    /**
     * @param non-empty-string $name
     */
    public function register(string $name, Sender $sender): void
    {
        $this->senders[$name] = $sender;
    }

    /**
     * @param non-empty-string[] $types
     * @return Sender[]
     */
    public function getSenders(array $types): array
    {
        $senders = [];
        foreach ($types as $type) {
            if (!isset($this->senders[$type])) {
                throw new \InvalidArgumentException(\sprintf('Unknown sender type "%s"', $type));
            }

            $senders[] = $this->senders[$type];
        }

        return $senders;
    }
}
