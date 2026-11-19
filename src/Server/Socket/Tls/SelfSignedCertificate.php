<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Socket\Tls;

/**
 * A self-signed certificate for `localhost`, `127.0.0.1` and `::1`, kept in a PEM file with its key.
 *
 * @internal
 */
final class SelfSignedCertificate
{
    /** Browsers reject a certificate valid for longer */
    private const VALID_DAYS = 397;

    /** Seconds before the expiry when the certificate is generated anew */
    private const RENEW_BEFORE = 86_400;

    /**
     * ext-openssl reads a config for any key or certificate operation, and on Windows there is none at the
     * default path.
     */
    private const OPENSSL_CONFIG = <<<'CNF'
        [req]
        # Checked for an EC key too: some builds of PHP reject the key when it is missing
        default_bits = 2048
        distinguished_name = dn
        [dn]
        [v3]
        subjectAltName = DNS:localhost, IP:127.0.0.1, IP:::1
        basicConstraints = critical, CA:FALSE
        keyUsage = critical, digitalSignature, keyEncipherment
        extendedKeyUsage = serverAuth
        CNF;

    /**
     * Returns the certificate file, generating it when it is missing, broken or about to expire.
     *
     * @param non-empty-string $directory
     *
     * @return non-empty-string Path to the PEM file with the certificate and its key.
     *
     * @throws \RuntimeException
     */
    public static function ensure(string $directory): string
    {
        $path = $directory . \DIRECTORY_SEPARATOR . 'localhost.pem';
        \is_dir($directory) or @\mkdir($directory, 0700, true) or \is_dir($directory)
            or throw new \RuntimeException(\sprintf('Failed to create the directory `%s`.', $directory));
        self::checkOwner($directory);

        if (self::isUsable($path)) {
            return $path;
        }

        $pem = self::generate($directory);
        // Another Trap may be reading the file: it gets either the old pair or the new one
        $temp = $path . '.' . \bin2hex(\random_bytes(4));
        $file = @\fopen($temp, 'x');
        $file === false and throw new \RuntimeException(\sprintf('Failed to write the certificate to `%s`.', $temp));
        // The key is not readable by others even for a moment
        @\chmod($temp, 0600);
        $written = \fwrite($file, $pem);
        \fclose($file);
        if ($written !== \strlen($pem)) {
            @\unlink($temp);
            throw new \RuntimeException(\sprintf('Failed to write the certificate to `%s`.', $temp));
        }
        if (!@\rename($temp, $path)) {
            @\unlink($temp);
            throw new \RuntimeException(\sprintf('Failed to write the certificate to `%s`.', $path));
        }

        return $path;
    }

    /**
     * @return int|null The user ID the process runs as, null where files have no owners to compare, as on Windows.
     */
    public static function userId(): ?int
    {
        if (\function_exists('posix_geteuid')) {
            return \posix_geteuid();
        }

        if (\PHP_OS_FAMILY === 'Windows') {
            return null;
        }

        // The static binaries come without ext-posix: a new file belongs to the user who creates it
        $probe = @\tempnam(\sys_get_temp_dir(), 'trap-uid');
        if ($probe === false) {
            return null;
        }

        $owner = @\fileowner($probe);
        @\unlink($probe);
        return $owner === false ? null : $owner;
    }

    /**
     * In a shared temporary directory another user could create the directory first and plant a key
     * they know.
     *
     * @throws \RuntimeException
     */
    private static function checkOwner(string $directory): void
    {
        $user = self::userId();
        if ($user === null) {
            return;
        }

        \is_link($directory) and throw new \RuntimeException(
            \sprintf('The directory `%s` is a symbolic link.', $directory),
        );
        @\fileowner($directory) === $user or throw new \RuntimeException(
            \sprintf('The directory `%s` belongs to another user.', $directory),
        );
        @\chmod($directory, 0700);
    }

    private static function isUsable(string $path): bool
    {
        $pem = @\file_get_contents($path);
        if ($pem === false) {
            return false;
        }

        $certificate = @\openssl_x509_read($pem);
        $info = $certificate === false ? false : \openssl_x509_parse($certificate);
        $result = $certificate !== false
            && $info !== false
            && (int) ($info['validTo_time_t'] ?? 0) > \time() + self::RENEW_BEFORE
            && \openssl_x509_check_private_key($certificate, $pem);
        self::clearErrors();

        return $result;
    }

    /**
     * @return string The certificate followed by its key, in PEM.
     */
    private static function generate(string $directory): string
    {
        $config = \tempnam($directory, 'cnf');
        $config === false and throw new \RuntimeException('Failed to write the OpenSSL config.');
        try {
            \file_put_contents($config, self::OPENSSL_CONFIG);
            $options = ['config' => $config, 'x509_extensions' => 'v3', 'digest_alg' => 'sha256'];

            $key = \openssl_pkey_new($options + [
                'private_key_type' => \OPENSSL_KEYTYPE_EC,
                'curve_name' => 'prime256v1',
            ]);
            $key === false and throw self::failure('Failed to generate a private key');

            $request = \openssl_csr_new(['commonName' => 'localhost'], $key, $options);
            $request === false and throw self::failure('Failed to make a certificate request');

            $certificate = \openssl_csr_sign(
                $request,
                null,
                $key,
                self::VALID_DAYS,
                $options,
                \random_int(1, \PHP_INT_MAX),
            );
            $certificate === false and throw self::failure('Failed to sign the certificate');

            \openssl_x509_export($certificate, $certificatePem) && \openssl_pkey_export($key, $keyPem, null, $options)
                or throw self::failure('Failed to export the certificate');
            /** @var string $certificatePem */
            /** @var string $keyPem */
            self::clearErrors();

            return $certificatePem . $keyPem;
        } finally {
            @\unlink($config);
        }
    }

    private static function failure(string $message): \RuntimeException
    {
        $errors = [];
        while (($error = \openssl_error_string()) !== false) {
            $errors[] = $error;
        }

        return new \RuntimeException($message . ($errors === [] ? '.' : ': ' . \implode('; ', $errors)));
    }

    /**
     * Even a successful operation can leave errors of reading the default config in the OpenSSL queue.
     */
    private static function clearErrors(): void
    {
        while (\openssl_error_string() !== false);
    }
}
