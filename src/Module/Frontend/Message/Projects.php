<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Frontend\Message;

use Buggregator\Trap\Module\Frontend\Event;

/**
 * @internal
 */
final class Projects implements \JsonSerializable
{
    /**
     * @param list<non-empty-string> $keys Projects of the events, besides the default one.
     */
    public function __construct(
        private readonly array $keys,
    ) {}

    #[\Override]
    public function jsonSerialize(): array
    {
        $data = [['name' => 'Default', 'key' => Event::DEFAULT_PROJECT, 'is_default' => true]];
        foreach ($this->keys as $key) {
            $key === Event::DEFAULT_PROJECT or $data[] = ['name' => $key, 'key' => $key, 'is_default' => false];
        }

        return ['data' => $data];
    }
}
