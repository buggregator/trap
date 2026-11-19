<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Socket\Tls;

use Buggregator\Trap\Server\Config\Tls as TlsConfig;
use Buggregator\Trap\Server\Logger;

/**
 * Options of the `ssl` stream context for accepting TLS connections.
 *
 * @internal
 */
final class Context
{
    /** Seconds a TLS handshake may take by default */
    public const HANDSHAKE_TIMEOUT = 10.0;

    /**
     * @param array<non-empty-string, string|bool> $options
     * @param float $handshakeTimeout Seconds a TLS handshake may take.
     */
    private function __construct(
        public readonly array $options,
        public readonly float $handshakeTimeout,
    ) {}

    /**
     * @param non-empty-string|null $directory Where a self-signed certificate is kept, a temporary one by default.
     *
     * @return self|null Null when TLS is unavailable: the reason is logged.
     */
    public static function create(TlsConfig $config, Logger $logger, ?string $directory = null): ?self
    {
        if (!\extension_loaded('openssl')) {
            $logger->info('TLS is off: it needs ext-openssl.');
            return null;
        }

        if ($config->cert !== null) {
            foreach ([$config->cert, $config->key] as $file) {
                if ($file !== null && !\is_readable($file)) {
                    $logger->warning('TLS is off: the file `%s` is not readable.', $file);
                    return null;
                }
            }

            return self::fromFiles($config->cert, $config->key);
        }

        try {
            $path = SelfSignedCertificate::ensure($directory ?? self::defaultDirectory());
        } catch (\Throwable $e) {
            $logger->warning('TLS is off: %s', $e->getMessage());
            return null;
        }

        $logger->status('TLS', 'Self-signed certificate: %s', $path);
        return self::fromFiles($path);
    }

    /**
     * @param non-empty-string $cert
     * @param non-empty-string|null $key Null when the key is in the certificate file.
     * @param float $handshakeTimeout Seconds a TLS handshake may take.
     */
    public static function fromFiles(
        string $cert,
        ?string $key = null,
        float $handshakeTimeout = self::HANDSHAKE_TIMEOUT,
    ): self {
        return new self([
            'local_cert' => $cert,
            ...($key === null ? [] : ['local_pk' => $key]),
            // A server that asks for a peer certificate wants mutual TLS
            'verify_peer' => false,
            // HTTP/2 is not served
            'alpn_protocols' => 'http/1.1',
        ], $handshakeTimeout);
    }

    /**
     * @return non-empty-string
     */
    private static function defaultDirectory(): string
    {
        // On Linux the temporary directory is shared by the users
        $user = \function_exists('posix_geteuid') ? (string) \posix_geteuid() : \get_current_user();

        return \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'trap-tls-' . (string) \preg_replace('/[^\w.-]/', '_', $user);
    }
}
