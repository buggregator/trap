<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Server\Socket;

use Buggregator\Trap\Server\Socket\Client;
use Buggregator\Trap\Server\Socket\Exception\ClientDisconnected;
use Buggregator\Trap\Server\Socket\Exception\TlsHandshakeFailed;
use Buggregator\Trap\Server\Socket\Tls\Context;
use Buggregator\Trap\Server\Socket\Tls\SelfSignedCertificate;
use Buggregator\Trap\Support\Activity;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Core\Exception\SkipTest;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Covers(Client::class)]
final class ClientTlsTest
{
    /** @var list<\Socket|resource> */
    private array $resources = [];

    /** @var list<string> */
    private array $payloads = [];

    #[BeforeTest]
    public function requireOpenssl(): void
    {
        \extension_loaded('openssl') or throw new SkipTest('TLS needs ext-openssl.');
        Activity::take();
    }

    #[AfterTest]
    public function close(): void
    {
        Activity::take();
        foreach ($this->resources as $resource) {
            try {
                $resource instanceof \Socket ? \socket_close($resource) : \fclose($resource);
            } catch (\Throwable) {
                // Already closed along with the other end of an export
            }
        }
        $this->resources = $this->payloads = [];
    }

    #[Test]
    public function acceptsTlsConnection(): void
    {
        [$client, $peer] = $this->tlsConnection();
        $fiber = new \Fiber($client->process(...));

        $this->handshake($fiber, $client, $peer);
        \fwrite($peer, 'hello over TLS');
        $this->drive($fiber, $client, fn(): bool => \implode('', $this->payloads) === 'hello over TLS');

        $client->send('reply');
        $reply = '';
        $this->drive($fiber, $client, static function () use ($peer, &$reply): bool {
            $reply .= (string) \fread($peer, 100);
            return $reply === 'reply';
        });

        Assert::same($reply, 'reply');
        $client->destroy();
    }

    #[Test]
    public function acceptsPlainConnectionWhenTlsIsOn(): void
    {
        [$client, $remote] = $this->plainConnection();
        $fiber = new \Fiber($client->process(...));

        \socket_write($remote, "GET / HTTP/1.1\r\n");
        $this->drive($fiber, $client, fn(): bool => \implode('', $this->payloads) === "GET / HTTP/1.1\r\n");

        $client->send('reply');
        $reply = '';
        $this->drive($fiber, $client, static function () use ($remote, &$reply): bool {
            return @\socket_recv($remote, $reply, 100, 0) > 0;
        });

        Assert::same($reply, 'reply');
        $client->destroy();
    }

    #[Test]
    public function readsTlsDataLongerThanPayloadSize(): void
    {
        [$client, $peer] = $this->tlsConnection(payloadSize: 1000);
        $fiber = new \Fiber($client->process(...));
        $this->handshake($fiber, $client, $peer);

        $data = \str_repeat('0123456789', 5000);
        $written = 0;
        $this->drive($fiber, $client, function () use ($peer, $data, &$written): bool {
            $written < \strlen($data) and $written += (int) \fwrite($peer, \substr($data, $written));
            return \implode('', $this->payloads) === $data;
        });

        Assert::true(\max(\array_map(\strlen(...), $this->payloads)) <= 1000);
        $client->destroy();
    }

    #[Test]
    public function acceptsClientHelloSplitAcrossReads(): void
    {
        [$client, $remote] = $this->plainConnection();
        [$peer, $relay] = $this->relayedPeer();
        $fiber = new \Fiber($client->process(...));

        // Starts the client handshake: the ClientHello waits in the relay
        @\stream_socket_enable_crypto($peer, true, \STREAM_CRYPTO_METHOD_TLS_CLIENT);
        $hello = '';
        $this->drive($fiber, $client, static function () use ($relay, &$hello): bool {
            @\socket_recv($relay, $chunk, 65536, 0) > 0 and $hello .= $chunk;
            return \strlen($hello) > 5;
        });
        \socket_write($remote, \substr($hello, 0, 3));
        $this->drive($fiber, $client, static fn(): bool => true);
        \socket_write($remote, \substr($hello, 3));

        $done = false;
        $this->drive($fiber, $client, static function () use ($peer, $relay, $remote, &$done): bool {
            self::forward($relay, $remote);
            self::forward($remote, $relay);
            $done = $done || @\stream_socket_enable_crypto($peer, true, \STREAM_CRYPTO_METHOD_TLS_CLIENT) === true;
            return $done;
        });

        \fwrite($peer, 'after a split hello');
        $this->drive($fiber, $client, function () use ($relay, $remote): bool {
            self::forward($relay, $remote);
            return \implode('', $this->payloads) === 'after a split hello';
        });

        Assert::same(\implode('', $this->payloads), 'after a split hello');
        $client->destroy();
    }

    #[Test]
    public function switchesPlainConnectionToTls(): void
    {
        [$client, $peer] = $this->tlsConnection();
        $fiber = new \Fiber($client->process(...));
        \fwrite($peer, "STARTTLS\r\n");
        $this->drive($fiber, $client, fn(): bool => $this->payloads === ["STARTTLS\r\n"]);

        $client->send("220 Ready\r\n");
        $client->startTls();
        Assert::false($client->canStartTls());
        $reply = '';
        $this->drive($fiber, $client, static function () use ($peer, &$reply): bool {
            $reply .= (string) \fread($peer, 100);
            return $reply === "220 Ready\r\n";
        });
        $this->handshake($fiber, $client, $peer);
        \fwrite($peer, "EHLO\r\n");
        $this->drive($fiber, $client, fn(): bool => \implode('', $this->payloads) === "STARTTLS\r\nEHLO\r\n");

        Assert::false($client->canStartTls());
        $client->destroy();
    }

    #[Test]
    public function cannotStartTlsWithoutContext(): void
    {
        [$listener, $port] = $this->listen();
        $remote = \socket_create(\AF_INET, \SOCK_STREAM, \SOL_TCP);
        \socket_connect($remote, '127.0.0.1', $port);
        $this->resources[] = $remote;
        $socket = \socket_accept($listener);
        \assert($socket instanceof \Socket);
        $client = Client::init($socket);

        Assert::false($client->canStartTls());
        Expect::exception(\LogicException::class);
        try {
            $client->startTls();
        } finally {
            $client->destroy();
        }
    }

    #[Test]
    public function dropsHandshakeThatNeverCompletes(): void
    {
        [$client, $remote] = $this->plainConnection(handshakeTimeout: 0.1);
        $fiber = new \Fiber($client->process(...));

        \socket_write($remote, "\x16\x03\x01");

        Expect::exception(TlsHandshakeFailed::class);
        try {
            $this->drive($fiber, $client, static fn(): bool => false, timeout: 2.0);
        } finally {
            $client->destroy();
        }
    }

    #[Test]
    public function failsHandshakeThatIsNotTls(): void
    {
        [$client, $remote] = $this->plainConnection();
        $fiber = new \Fiber($client->process(...));

        \socket_write($remote, "\x16" . \str_repeat("\xFF", 100));

        Expect::exception(TlsHandshakeFailed::class);
        try {
            $this->drive($fiber, $client, static fn(): bool => false, timeout: 2.0);
        } finally {
            $client->destroy();
        }
    }

    #[Test]
    public function disconnectsWhenTlsPeerCloses(): void
    {
        [$client, $peer] = $this->tlsConnection();
        $fiber = new \Fiber($client->process(...));
        $this->handshake($fiber, $client, $peer);

        \fclose($peer);

        Expect::exception(ClientDisconnected::class);
        try {
            $this->drive($fiber, $client, static fn(): bool => false, timeout: 2.0);
        } finally {
            $client->destroy();
        }
    }

    private static function context(float $handshakeTimeout = Context::HANDSHAKE_TIMEOUT): Context
    {
        $directory = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'trap-tls-tests';

        return Context::fromFiles(SelfSignedCertificate::ensure($directory), handshakeTimeout: $handshakeTimeout);
    }

    private static function forward(\Socket $from, \Socket $to): void
    {
        $chunk = '';
        @\socket_recv($from, $chunk, 65536, 0) > 0 and \socket_write($to, $chunk);
    }

    /**
     * Runs the client until the condition is met.
     *
     * @param \Closure(): bool $condition
     */
    private function drive(\Fiber $fiber, Client $client, \Closure $condition, float $timeout = 5.0): void
    {
        $until = \microtime(true) + $timeout;
        do {
            $client->wake();
            $fiber->isStarted() ? $fiber->resume() : $fiber->start();
            if ($condition()) {
                return;
            }
            \usleep(1000);
        } while (\microtime(true) < $until);

        throw new \RuntimeException('The condition is not met in time.');
    }

    /**
     * @param resource $peer
     */
    private function handshake(\Fiber $fiber, Client $client, mixed $peer): void
    {
        $this->drive(
            $fiber,
            $client,
            static fn(): bool => @\stream_socket_enable_crypto($peer, true, \STREAM_CRYPTO_METHOD_TLS_CLIENT) === true,
        );
    }

    /**
     * @param positive-int $payloadSize
     *
     * @return array{Client, \Socket} The client on the server side, the socket of its peer.
     */
    private function plainConnection(
        int $payloadSize = 1024 * 1024,
        float $handshakeTimeout = Context::HANDSHAKE_TIMEOUT,
    ): array {
        [$listener, $port] = $this->listen();
        $remote = \socket_create(\AF_INET, \SOCK_STREAM, \SOL_TCP);
        \socket_connect($remote, '127.0.0.1', $port);
        \socket_set_nonblock($remote);
        $this->resources[] = $remote;

        return [$this->accept($listener, $payloadSize, $handshakeTimeout), $remote];
    }

    /**
     * @param positive-int $payloadSize
     *
     * @return array{Client, resource} The client on the server side, the non-blocking stream of its peer.
     */
    private function tlsConnection(int $payloadSize = 1024 * 1024): array
    {
        [$listener, $port] = $this->listen();
        $peer = $this->tlsPeer($port);

        return [$this->accept($listener, $payloadSize), $peer];
    }

    /**
     * @return array{resource, \Socket} A non-blocking TLS stream, the socket of the relay it connects to.
     */
    private function relayedPeer(): array
    {
        [$listener, $port] = $this->listen();
        $peer = $this->tlsPeer($port);
        $relay = \socket_accept($listener);
        \assert($relay instanceof \Socket);
        \socket_set_nonblock($relay);
        $this->resources[] = $relay;

        return [$peer, $relay];
    }

    /**
     * @param int<1, 65535> $port
     *
     * @return resource
     */
    private function tlsPeer(int $port): mixed
    {
        $context = \stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
        $peer = \stream_socket_client("tcp://127.0.0.1:$port", context: $context);
        \assert($peer !== false);
        \stream_set_blocking($peer, false);
        $this->resources[] = $peer;

        return $peer;
    }

    /**
     * @return array{\Socket, int<1, 65535>}
     */
    private function listen(): array
    {
        $listener = \socket_create(\AF_INET, \SOCK_STREAM, \SOL_TCP);
        \socket_bind($listener, '127.0.0.1', 0);
        \socket_listen($listener);
        \socket_getsockname($listener, $address, $port);
        $this->resources[] = $listener;
        \assert($port > 0 && $port < 65536);

        return [$listener, $port];
    }

    /**
     * @param positive-int $payloadSize
     */
    private function accept(
        \Socket $listener,
        int $payloadSize,
        float $handshakeTimeout = Context::HANDSHAKE_TIMEOUT,
    ): Client {
        $socket = \socket_accept($listener);
        \assert($socket instanceof \Socket);
        $client = Client::init($socket, $payloadSize, selectPeriod: 3600.0, tls: self::context($handshakeTimeout));
        // A non-static closure would be bound to the client
        $payloads = &$this->payloads;
        $client->setOnPayload(static function (string $payload) use (&$payloads): void {
            $payloads[] = $payload;
        });

        return $client;
    }
}
