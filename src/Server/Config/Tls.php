<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Config;

use Buggregator\Trap\Server\Application\Config\Internal\Attribute\Env;
use Buggregator\Trap\Server\Application\Config\Internal\Attribute\InflectableConfig;
use Buggregator\Trap\Server\Application\Config\Internal\Attribute\XPath;

/**
 * TLS on the listened ports, next to plain traffic.
 *
 * @internal
 * @psalm-internal Buggregator\Trap
 */
#[InflectableConfig]
final class Tls
{
    /**
     * Path to a PEM certificate. Without it a self-signed one for `localhost` is generated.
     *
     * @var non-empty-string|null
     */
    #[Env('TRAP_TLS_CERT')]
    #[XPath('/trap/tls/@cert')]
    public ?string $cert = null;

    /**
     * Path to the PEM private key of the certificate. Null when the key is in the certificate file.
     *
     * @var non-empty-string|null
     */
    #[Env('TRAP_TLS_KEY')]
    #[XPath('/trap/tls/@key')]
    public ?string $key = null;
}
