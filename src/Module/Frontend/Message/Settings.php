<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Frontend\Message;

use Buggregator\Trap\Server\Info;

/**
 * @internal
 */
final class Settings implements \JsonSerializable
{
    public readonly string $number;

    /**
     * @param list<non-empty-string> $events Event types trap produces. The UI hides the others from its menu.
     */
    public function __construct(
        private readonly array $events,
    ) {
        $this->number = Info::version();
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        return [
            'auth' => [
                'enabled' => false,
                'login_url' => '/auth/sso/login',
            ],
            'version' => $this->number,
            'client' => [
                'events' => $this->events,
            ],
        ];
    }
}
