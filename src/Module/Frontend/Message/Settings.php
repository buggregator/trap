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
     * @param array<non-empty-string, int> $ports Ports of the listeners for the setup guide of the UI,
     *        by `http`, `smtp`, `monolog`, `var_dumper`, `proxy`, `xdebug`: the UI shows a missing one as off.
     */
    public function __construct(
        private readonly array $events,
        private readonly array $ports = [],
    ) {
        // The UI prints the version after a `v` and only recognizes one that starts with a digit
        $this->number = Info::version() . '-trap';
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
            'events' => $this->events,
            // The UI before 1.39 reads the types here
            'client' => [
                'events' => $this->events,
            ],
            'ports' => (object) $this->ports,
        ];
    }
}
