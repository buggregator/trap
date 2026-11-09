<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Frontend\Message;

use Buggregator\Trap\Info;

/**
 * @internal
 */
final class Settings implements \JsonSerializable
{
    /**
     * Event types trap produces. The UI hides the others from its menu.
     */
    private const EVENTS = ['var-dump', 'smtp', 'sentry', 'profiler', 'monolog', 'ray', 'http-dump'];

    public readonly string $number;

    public function __construct()
    {
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
                'events' => self::EVENTS,
            ],
        ];
    }
}
