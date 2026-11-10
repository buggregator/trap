<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Frontend\Message;

use Buggregator\Trap\Server\Info;

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

    #[\Override]
    public function jsonSerialize(): array
    {
        return [
            'version' => $this->number,
        ];
    }
}
