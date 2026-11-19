<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Client\Stub;

/**
 * A TLS server in a child process with a self-signed certificate for `localhost` and `127.0.0.1`.
 * It reports the first line of every connection, and an empty line for a failed handshake.
 *
 * It runs in another process because the client waits for the handshake to complete.
 */
final class TlsCatcher
{
    private const SERVER = <<<'PHP'
        $context = stream_context_create(['ssl' => ['local_cert' => $argv[1], 'local_pk' => $argv[2]]]);
        $server = stream_socket_server('tls://127.0.0.1:0', $errorCode, $errorMessage, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context) or exit(1);
        echo substr(strrchr(stream_socket_get_name($server, false), ':'), 1), "\n";
        while (true) {
            $client = @stream_socket_accept($server, 60);
            if ($client === false) {
                echo "\n";
                continue;
            }
            $line = rtrim((string) @fgets($client), "\n");
            fclose($client);
            echo base64_encode((string) $line), "\n";
        }
        PHP;
    private const OPENSSL_CONFIG = <<<'INI'
        [req]
        distinguished_name = dn
        [dn]
        [v3]
        subjectAltName = DNS:localhost, IP:127.0.0.1
        INI;

    public readonly int $port;

    /** @var resource */
    private $process;

    /** @var resource */
    private $output;

    /** @var resource */
    private $errors;

    /** @var list<non-empty-string> */
    private array $files = [];

    public function __construct()
    {
        [$certificate, $key] = $this->createCertificate();

        $process = \proc_open(
            [\PHP_BINARY, '-r', self::SERVER, '--', $certificate, $key],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $process === false and throw new \RuntimeException('Unable to start the TLS catcher.');
        $this->process = $process;
        $this->output = $pipes[1];
        $this->errors = $pipes[2];
        $this->port = (int) \fgets($this->output);
        $this->port > 0 or throw new \RuntimeException('The TLS catcher did not report its port.');
    }

    /**
     * Returns the next received line, or null for a connection whose handshake failed.
     *
     * @throws \RuntimeException When no connection comes within a few seconds.
     */
    public function line(): ?string
    {
        $read = [$this->output];
        $write = $except = [];
        \stream_select($read, $write, $except, 5) === 1
            or throw new \RuntimeException('The TLS catcher received no connection.');

        $line = \fgets($this->output);
        $line === false and throw new \RuntimeException('The TLS catcher has stopped.');
        $line = \trim($line);

        return $line === '' ? null : \base64_decode($line);
    }

    public function close(): void
    {
        \proc_terminate($this->process);
        \fclose($this->output);
        \fclose($this->errors);
        \proc_close($this->process);
        foreach ($this->files as $file) {
            \is_file($file) and \unlink($file);
        }
    }

    /**
     * @return array{non-empty-string, non-empty-string} Paths to the PEM certificate and key.
     */
    private function createCertificate(): array
    {
        // Without a config file OpenSSL looks for the system one, which Windows doesn't have.
        $config = $this->tempFile(self::OPENSSL_CONFIG);
        $options = ['config' => $config, 'x509_extensions' => 'v3', 'private_key_bits' => 2048];

        try {
            $key = \openssl_pkey_new($options + ['private_key_type' => \OPENSSL_KEYTYPE_RSA]);
            $key === false and throw new \RuntimeException('Unable to create a key.');
            $csr = \openssl_csr_new(['commonName' => 'localhost'], $key, $options);
            $csr === false || $csr === true and throw new \RuntimeException('Unable to create a CSR.');
            $certificate = \openssl_csr_sign($csr, null, $key, 1, $options);
            $certificate === false and throw new \RuntimeException('Unable to sign the certificate.');
            \openssl_x509_export($certificate, $certificatePem) or throw new \RuntimeException('Unable to export the certificate.');
            \openssl_pkey_export($key, $keyPem, null, $options) or throw new \RuntimeException('Unable to export the key.');
        } finally {
            while (\openssl_error_string() !== false);
        }

        return [$this->tempFile($certificatePem), $this->tempFile($keyPem)];
    }

    /**
     * @return non-empty-string
     */
    private function tempFile(string $content): string
    {
        $file = \tempnam(\sys_get_temp_dir(), 'trap-tls-');
        $file === false and throw new \RuntimeException('Unable to create a temporary file.');
        \file_put_contents($file, $content);

        return $this->files[] = $file;
    }
}
