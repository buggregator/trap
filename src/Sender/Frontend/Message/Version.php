<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender\Frontend\Message;

use Buggregator\Trap\Info;

/**
 * @internal
 */
final class Version implements \JsonSerializable
{
    public function __construct(
        public readonly string $number = Info::VERSION,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'version' => $this->number,
        ];
    }
}
