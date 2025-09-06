<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Frontend\Message;

use Buggregator\Trap\Info;

/**
 * @internal
 */
final class Version implements \JsonSerializable
{
    public readonly string $number;

    public function __construct()
    {
        $this->number = Info::version();
    }

    public function jsonSerialize(): array
    {
        return [
            'version' => $this->number,
        ];
    }
}
