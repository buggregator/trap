<?php

declare(strict_types=1);

namespace Buggregator\Trap\Client\Profiler\Internal;

use Buggregator\Trap\Client\Profiler\Format;
use Buggregator\Trap\Client\Profiler\Profile;

/**
 * Posts a profile in its native format to the Trap HTTP endpoints.
 *
 * @internal
 */
final class TrapSender
{
    private const DEFAULT_HOST = '127.0.0.1';
    private const DEFAULT_PORT = 9912;

    /**
     * @param int<1, 65535> $port
     */
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly HttpClient $http = new HttpClient(),
    ) {}

    /**
     * Missing arguments come from the `TRAP_PROFILER_HOST` and `TRAP_PROFILER_PORT` env variables,
     * then default to `127.0.0.1:9912`.
     *
     * The host may carry a port: `host:port` or `[ipv6]:port`. It loses to the port argument
     * and wins over the env variable.
     */
    public static function create(?string $host = null, ?int $port = null): self
    {
        $host ??= Env::get('TRAP_PROFILER_HOST') ?? self::DEFAULT_HOST;

        $hostPort = null;
        if (\preg_match('/^\[([^\]]*)\](?::(\d+))?$/', $host, $match) === 1) {
            [$host, $hostPort] = [$match[1], $match[2] ?? null];
        } elseif (\substr_count($host, ':') === 1) {
            [$host, $hostPort] = \explode(':', $host);
        }

        $host === '' and $host = self::DEFAULT_HOST;

        if ($port === null) {
            $env = \is_numeric($hostPort) ? $hostPort : Env::get('TRAP_PROFILER_PORT');
            $port = \is_numeric($env) ? (int) $env : self::DEFAULT_PORT;
        }

        $port >= 1 && $port <= 65535 or $port = self::DEFAULT_PORT;

        return new self($host, $port);
    }

    /**
     * Sends a recorded profile: Speedscope when the profiler produces it, XHProf otherwise.
     * Profiles Trap has no endpoint for are dropped.
     *
     * @throws \RuntimeException When the profile is not delivered.
     */
    public function send(Profile $profile): void
    {
        $formats = $profile->formats();
        match (true) {
            \in_array(Format::Speedscope, $formats, true) => $this->post(
                '/api/profiler/speedscope' . $this->withQuery(Payload::query($profile)),
                $profile->encode(Format::Speedscope),
            ),
            \in_array(Format::XHProf, $formats, true) => $this->post('/api/profiler/store', Payload::store($profile)),
            default => null,
        };
    }

    private function withQuery(string $query): string
    {
        return $query === '' ? '' : '?' . $query;
    }

    /**
     * @param non-empty-string $target
     */
    private function post(string $target, string $body): void
    {
        $this->http->post('http', $this->host, $this->port, $target, ['Content-Type' => 'application/json'], $body);
    }
}
