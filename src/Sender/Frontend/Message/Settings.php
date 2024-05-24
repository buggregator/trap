<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender\Frontend\Message;

use Buggregator\Trap\Info;

/**
 * @internal
 */
final class Settings implements \JsonSerializable
{
    public readonly string $number;

    public function __construct()
    {
        $this->number = Info::version();
    }

    public function jsonSerialize(): array
    {
        return [
            'auth' => [
                'enabled' => false,
                'login_url' => '/auth/sso/login',
            ],
            'version' => $this->number,
        ];
    }
}
