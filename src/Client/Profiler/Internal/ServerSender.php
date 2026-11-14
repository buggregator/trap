<?php

declare(strict_types=1);

namespace Buggregator\Trap\Client\Profiler\Internal;

use Buggregator\Trap\Client\Profiler\Format;
use Buggregator\Trap\Client\Profiler\Profile;

/**
 * Posts a profile in a chosen format to any HTTP server.
 *
 * XHProf goes as the Buggregator store JSON with the metadata inside; Speedscope, Collapsed, pprof and SPX go
 * as the bare document with the metadata in the query.
 *
 * @internal
 */
final class ServerSender
{
    public const DEFAULT_URL = 'http://127.0.0.1:8000/api/profiler/store';

    /**
     * Headers the HTTP client writes itself, where a second copy would make the request ambiguous, and `Expect`:
     * the client reads only the final status line, never an interim `100 Continue`.
     */
    private const RESERVED = ['host', 'content-length', 'connection', 'transfer-encoding', 'expect'];

    /**
     * @param 'http'|'https' $scheme
     * @param int<1, 65535> $port
     * @param non-empty-string $path
     * @param array<non-empty-string, string> $headers
     * @param bool $contentType Whether to add the `Content-Type` of the format sent; false when the caller set or removed it.
     */
    private function __construct(
        private readonly string $scheme,
        private readonly string $host,
        private readonly int $port,
        private readonly string $path,
        private readonly string $query,
        private readonly array $headers,
        private readonly Format $format,
        private readonly bool $contentType,
        private readonly HttpClient $http = new HttpClient(),
    ) {}

    /**
     * @param Format $format Preferred: a profile that can't be encoded so goes in its native format.
     * @param string|null $url Null takes the `TRAP_PROFILER_SERVER` env variable, then {@see DEFAULT_URL}.
     *        Userinfo turns into Basic authorization.
     * @param array<string, string|null> $headers Merged over the generated ones, case-insensitively;
     *        null removes a header.
     *
     * @throws \InvalidArgumentException On an invalid URL or header.
     */
    public static function create(?string $url, Format $format, array $headers): self
    {
        $url ??= Env::get('TRAP_PROFILER_SERVER');
        $url === null || $url === '' and $url = self::DEFAULT_URL;

        // The path and query go into the request line, where spaces and control characters break it;
        // bytes above ASCII are percent-encoded below instead.
        \preg_match('/[\x00-\x20\x7F]/', $url) === 0
        or throw new \InvalidArgumentException(\sprintf('Invalid profiler server URL `%s`: spaces and control characters must be encoded.', $url));

        $parts = \parse_url($url);
        $parts === false and $parts = [];
        $scheme = \strtolower($parts['scheme'] ?? '');
        $host = \trim($parts['host'] ?? '', '[]');
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        ($scheme === 'http' || $scheme === 'https') && $host !== '' && $port > 0 && $port <= 65535
        or throw new \InvalidArgumentException(\sprintf('Invalid profiler server URL `%s`: an http or https URL is expected.', $url));

        $merged = [
            'x-buggregator-event' => ['X-Buggregator-Event', 'profiler'],
        ];
        if (isset($parts['user'])) {
            // RFC 7617: the colon stays when the password is empty.
            $userinfo = \rawurldecode($parts['user']) . ':' . \rawurldecode($parts['pass'] ?? '');
            $merged['authorization'] = ['Authorization', 'Basic ' . \base64_encode($userinfo)];
        }

        $contentType = true;
        foreach ($headers as $name => $value) {
            $name = (string) $name;
            $key = \strtolower($name);
            $key === 'content-type' and $contentType = false;
            // RFC 9110: a name is a token; a value has no control characters but the horizontal tab.
            \preg_match('/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/D', $name) === 1
            && ($value === null || \preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $value) === 0)
            or throw new \InvalidArgumentException(\sprintf('Invalid HTTP header `%s`.', \addcslashes($name, "\0..\37\177")));
            \in_array($key, self::RESERVED, true)
            and throw new \InvalidArgumentException(\sprintf('The `%s` header can not be passed: the client manages it.', $name));

            if ($value === null) {
                unset($merged[$key]);
            } else {
                $merged[$key] = [$name, $value];
            }
        }

        /** @var array<non-empty-string, string> $headers Names are validated tokens. */
        $headers = \array_column($merged, 1, 0);
        $path = self::encodeNonAscii($parts['path'] ?? '');

        return new self(
            scheme: $scheme,
            host: $host,
            port: $port,
            path: $path === '' ? '/' : $path,
            query: self::encodeNonAscii($parts['query'] ?? ''),
            headers: $headers,
            format: $format,
            contentType: $contentType,
        );
    }

    /**
     * An SPX report goes only where it is asked for: a server that takes another format, as the XHProf store
     * of Buggregator, can't read it.
     *
     * @throws \RuntimeException When the profile is not delivered.
     */
    public function send(Profile $profile): void
    {
        $formats = $profile->formats();
        $format = \in_array($this->format, $formats, true) ? $this->format : $formats[0];
        $format === Format::Spx && $this->format !== Format::Spx and throw new \RuntimeException(\sprintf(
            'The SPX profile can not be converted to %s without ext-zlib, and the server takes no SPX report.',
            $this->format->name,
        ));
        [$body, $query] = match ($format) {
            Format::XHProf => [Payload::store($profile), ''],
            default => [$profile->stream($format), Payload::query($profile)],
        };
        $headers = $this->headers;
        $this->contentType and $headers['Content-Type'] = match ($format) {
            Format::Collapsed => 'text/plain; charset=utf-8',
            Format::Pprof => 'application/octet-stream',
            Format::Spx => 'application/x-tar',
            default => 'application/json',
        };

        $query = \implode('&', \array_filter([$this->query, $query], static fn(string $part): bool => $part !== ''));
        $this->http->post(
            $this->scheme,
            $this->host,
            $this->port,
            $this->path . ($query === '' ? '' : '?' . $query),
            $headers,
            $body,
        );
    }

    /**
     * Percent-encodes the bytes above ASCII, which a request line can't carry raw; the rest stays as written.
     */
    private static function encodeNonAscii(string $part): string
    {
        return \preg_replace_callback(
            '/[\x80-\xFF]/',
            static fn(array $match): string => \sprintf('%%%02X', \ord($match[0])),
            $part,
        ) ?? $part;
    }
}
