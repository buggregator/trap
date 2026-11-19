<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Client\Stub;

use Testo\Core\Exception\SkipTest;

/**
 * A TLS server in a child process with a self-signed certificate for `localhost` and `127.0.0.1`.
 * For every accept it reports one marker line: {@see self::RECEIVED} with the first line of the connection
 * in base64, {@see self::FAILED_HANDSHAKE}, or {@see self::NO_CONNECTION} when none came within the accept timeout.
 *
 * It runs in another process because the client waits for the handshake to complete.
 */
final class TlsCatcher
{
    public const RECEIVED = 'ok:';
    public const FAILED_HANDSHAKE = 'F';
    public const NO_CONNECTION = 'T';
    private const ACCEPT_TIMEOUT = 5;

    /**
     * The child exits once writing to the parent fails, so it outlives a dead parent by one accept timeout
     * at most; the lifetime cap covers a parent that is alive but forgot it.
     */
    private const SERVER = <<<'PHP'
        [, $certificate, $key, $acceptTimeout] = $argv;
        if (!extension_loaded('openssl')) {
            echo "no-openssl\n";
            exit(1);
        }
        $context = stream_context_create(['ssl' => ['local_cert' => $certificate, 'local_pk' => $key]]);
        $server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context) or exit(1);
        echo substr(strrchr(stream_socket_get_name($server, false), ':'), 1), "\n";
        $stopAt = time() + 120;
        while (time() < $stopAt) {
            $client = @stream_socket_accept($server, (float) $acceptTimeout);
            if ($client === false) {
                $marker = 'T';
            } else {
                stream_set_timeout($client, (int) $acceptTimeout);
                $marker = @stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_SERVER) === true
                    ? 'ok:' . base64_encode(rtrim((string) @fgets($client), "\n"))
                    : 'F';
                fclose($client);
            }
            if (!@fwrite(STDOUT, "{$marker}\n")) {
                exit(0);
            }
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

    /** @var resource|null */
    private $process = null;

    /** @var resource|null */
    private $output = null;

    /** @var list<non-empty-string> */
    private array $files = [];

    /**
     * @throws SkipTest When ext-openssl is not loaded in this process or in the child.
     */
    public function __construct()
    {
        \extension_loaded('openssl') or throw new SkipTest('ext-openssl is required.');

        try {
            [$certificate, $key] = $this->createCertificate();

            $ini = \php_ini_loaded_file();
            $process = \proc_open(
                [\PHP_BINARY, ...($ini === false ? [] : ['-c', $ini]), '-r', self::SERVER, '--', $certificate, $key, (string) self::ACCEPT_TIMEOUT],
                [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['file', \DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null', 'w'],
                ],
                $pipes,
            );
            $process === false and throw new \RuntimeException('Unable to start the TLS catcher.');
            $this->process = $process;
            \fclose($pipes[0]);
            $this->output = $pipes[1];

            $line = \trim((string) \fgets($this->output));
            $line === 'no-openssl' and throw new SkipTest('ext-openssl is not loaded in the TLS catcher process.');
            $this->port = (int) $line;
            $this->port > 0 or throw new \RuntimeException('The TLS catcher did not report its port.');
        } catch (\Throwable $e) {
            $this->close();
            throw $e;
        }
    }

    /**
     * Returns the next marker line. Blocks for one accept timeout at most.
     */
    public function next(): string
    {
        \is_resource($this->output) or throw new \RuntimeException('The TLS catcher is closed.');
        $line = \fgets($this->output);
        $line === false and throw new \RuntimeException('The TLS catcher has stopped.');

        return \rtrim($line, "\n");
    }

    /**
     * Returns the first line of the next connection.
     *
     * @throws \RuntimeException When the next connection failed its handshake or none came.
     */
    public function line(): string
    {
        $marker = $this->next();
        \str_starts_with($marker, self::RECEIVED)
            or throw new \RuntimeException("The TLS catcher received no data, its marker: {$marker}.");

        return \base64_decode(\substr($marker, \strlen(self::RECEIVED)));
    }

    public function close(): void
    {
        if (\is_resource($this->process)) {
            \proc_terminate($this->process);
        }
        if (\is_resource($this->output)) {
            \fclose($this->output);
        }
        if (\is_resource($this->process)) {
            \proc_close($this->process);
        }
        $this->process = $this->output = null;
        foreach ($this->files as $file) {
            \is_file($file) and \unlink($file);
        }
        $this->files = [];
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
