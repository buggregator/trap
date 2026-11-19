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
     * @param bool $trusted The certificate is the user's own, which clients may trust. A protocol offers TLS
     *        to a plain client, such as SMTP `STARTTLS`, only then: mailers take the offer by themselves and
     *        fail on a certificate they can't verify.
     * @param float $handshakeTimeout Seconds a TLS handshake may take.
     */
    private function __construct(
        public readonly array $options,
        public readonly bool $trusted,
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
            // A broken pair fails every handshake, which is logged only in the debug mode
            $error = self::checkPair($config->cert, $config->key ?? $config->cert);
            if ($error !== null) {
                $logger->warning('TLS is off: %s', $error);
                return null;
            }

            return self::fromFiles($config->cert, $config->key);
        }

        $config->key === null or $logger->warning('TLS ignores the key `%s`: no certificate is set for it.', $config->key);

        try {
            $path = SelfSignedCertificate::ensure($directory ?? self::defaultDirectory());
        } catch (\Throwable $e) {
            $logger->warning('TLS is off: %s', $e->getMessage());
            return null;
        }

        $logger->status('TLS', 'Self-signed certificate: %s', $path);
        return self::fromFiles($path, trusted: false);
    }

    /**
     * @param non-empty-string $cert
     * @param non-empty-string|null $key Null when the key is in the certificate file.
     * @param bool $trusted The certificate is the user's own, see {@see self::$trusted}.
     * @param float $handshakeTimeout Seconds a TLS handshake may take.
     */
    public static function fromFiles(
        string $cert,
        ?string $key = null,
        bool $trusted = true,
        float $handshakeTimeout = self::HANDSHAKE_TIMEOUT,
    ): self {
        return new self([
            'local_cert' => $cert,
            ...($key === null ? [] : ['local_pk' => $key]),
            // A server that asks for a peer certificate wants mutual TLS
            'verify_peer' => false,
            // HTTP/2 is not served
            'alpn_protocols' => 'http/1.1',
        ], $trusted, $handshakeTimeout);
    }

    /**
     * @return string|null The certificate or key file that has gone, as a cleaner of the temporary directory
     *         may remove it: every handshake fails then.
     */
    public function missingFile(): ?string
    {
        foreach (['local_cert', 'local_pk'] as $option) {
            $file = $this->options[$option] ?? null;
            if (\is_string($file) && !\is_file($file)) {
                return $file;
            }
        }

        return null;
    }

    /**
     * @return string|null Why the certificate and the key can't be used, null when they can.
     */
    private static function checkPair(string $cert, string $key): ?string
    {
        foreach ([$cert, $key] as $file) {
            if (!\is_readable($file)) {
                return \sprintf('the file `%s` is not readable.', $file);
            }
        }

        $certificate = @\openssl_x509_read((string) \file_get_contents($cert));
        $privateKey = @\openssl_pkey_get_private((string) \file_get_contents($key));
        $error = match (true) {
            $certificate === false => \sprintf('no certificate in `%s`.', $cert),
            $privateKey === false => \sprintf('no private key in `%s`.', $key),
            !\openssl_x509_check_private_key($certificate, $privateKey) => \sprintf(
                'the key in `%s` does not match the certificate in `%s`.',
                $key,
                $cert,
            ),
            default => null,
        };
        while (\openssl_error_string() !== false);

        return $error;
    }

    /**
     * @return non-empty-string
     */
    private static function defaultDirectory(): string
    {
        // On Linux the temporary directory is shared by the users. get_current_user() would name the owner of
        // the script, not the user who runs it.
        $user = (string) SelfSignedCertificate::userId();
        foreach (['USERNAME', 'USER'] as $name) {
            $value = \getenv($name);
            $user === '' && \is_string($value) and $user = $value;
        }
        $user === '' and $user = 'user';

        return \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'trap-tls-' . (string) \preg_replace('/[^\w.-]/', '_', $user);
    }
}
