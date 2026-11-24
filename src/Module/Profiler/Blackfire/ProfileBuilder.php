<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Profiler\Blackfire;

use Buggregator\Trap\Module\Profiler\Struct\Profile;
use Buggregator\Trap\Module\Profiler\XHProf\ProfileBuilder as XHProfProfileBuilder;
use Buggregator\Trap\Support\TimeSlice;

/**
 * Builds a profile from what the Blackfire probe sends to its agent: `key: value` header lines, an empty line,
 * then a line per caller and callee, `caller==>callee//<calls> <costs>`, with the costs in the order
 * of the `cost-dimensions` header. It's the call graph of XHProf as text, recursion marked as `fn@1` too.
 *
 * The profile takes `wt`, `cpu`, `mu` and `pmu`; the network and garbage collector costs are dropped.
 *
 * @psalm-import-type Metadata from Profile
 *
 * @internal
 */
final class ProfileBuilder
{
    private const METRICS = ['wt', 'cpu', 'mu', 'pmu'];

    public function __construct(
        private readonly XHProfProfileBuilder $xhprof = new XHProfProfileBuilder(),
    ) {}

    /**
     * @param iterable<string> $lines The data, line by line, with or without the line ends.
     * @param array<array-key, mixed> $query The parameters of the Blackfire query the profile was asked with:
     *        `profile_title` becomes the `name` tag.
     *
     * @throws \InvalidArgumentException When the data is malformed.
     */
    public function createProfile(
        iterable $lines,
        array $query = [],
        ?\DateTimeImmutable $date = null,
        ?TimeSlice $timeSlice = null,
    ): Profile {
        $headers = [];
        /** @var array<int, string>|null $dimensions Metric names by the position of their cost; null in the headers */
        $dimensions = null;
        $calls = [];
        foreach ($lines as $line) {
            $line = \rtrim($line, "\r\n");
            if ($dimensions === null) {
                if ($line === '') {
                    $dimensions = self::dimensions($headers);
                    continue;
                }
                [$key, $value] = \explode(':', $line, 2) + [1 => ''];
                // The probe repeats `request-mu` and `request-pmu` at the end of the request: the last one is kept
                $headers[\strtolower(\trim($key))] = \trim($value);
                continue;
            }

            $line === '' or self::addCall($calls, $line, $dimensions);
            $timeSlice?->tick();
        }

        $calls === [] and throw new \InvalidArgumentException('The profile has no calls.');

        $start = $headers['request-start'] ?? '';
        $date ??= \is_numeric($start)
            ? \DateTimeImmutable::createFromFormat('U.u', \sprintf('%.6F', (float) $start)) ?: null
            : null;

        /** @psalm-suppress ArgumentTypeCoercion The costs of memory may be negative, as of XHProf. */
        return $this->xhprof->createProfile(
            date: $date ?? new \DateTimeImmutable(),
            metadata: self::metadata($headers),
            tags: self::tags($headers, $query),
            calls: $calls,
            timeSlice: $timeSlice,
        );
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array<int, string>
     */
    private static function dimensions(array $headers): array
    {
        ($headers['file-format'] ?? null) === 'BlackfireProbe'
            or throw new \InvalidArgumentException('Not a profile of the Blackfire probe: no `file-format: BlackfireProbe`.');

        $dimensions = \preg_split('/\s+/', $headers['cost-dimensions'] ?? '', -1, \PREG_SPLIT_NO_EMPTY);
        \is_array($dimensions) && \in_array('wt', $dimensions, true)
            or throw new \InvalidArgumentException('The `cost-dimensions` header has no `wt`.');

        return $dimensions;
    }

    /**
     * @param array<string, array<string, int>> $calls
     * @param array<int, string> $dimensions
     */
    private static function addCall(array &$calls, string $line, array $dimensions): void
    {
        // A file path in the name may hold `//`, the costs never do
        $pos = \strrpos($line, '//');
        $values = $pos === false ? [] : \explode(' ', \substr($line, $pos + 2));
        if ($pos === false || $pos === 0 || \count($values) <= \count($dimensions)
            || \preg_match('/^\d+$/', $values[0]) !== 1
        ) {
            throw new \InvalidArgumentException(\sprintf('Malformed call: `%s`.', \mb_strimwidth($line, 0, 120, '...')));
        }

        $name = \substr($line, 0, $pos);
        $call = ['ct' => (int) $values[0]];
        foreach ($dimensions as $i => $metric) {
            $value = $values[$i + 1];
            \preg_match('/^-?\d+$/', $value) === 1
                or throw new \InvalidArgumentException(\sprintf('Malformed cost `%s` of `%s`.', $value, $name));
            \in_array($metric, self::METRICS, true) and $call[$metric] = (int) $value;
        }

        if (isset($calls[$name])) {
            foreach ($call as $metric => $value) {
                $calls[$name][$metric] = ($calls[$name][$metric] ?? 0) + $value;
            }
            return;
        }
        $calls[$name] = $call + ['cpu' => 0, 'mu' => 0, 'pmu' => 0];
    }

    /**
     * @param array<string, string> $headers
     *
     * @return Metadata
     */
    private static function metadata(array $headers): array
    {
        $metadata = [];
        ($headers['host'] ?? '') === '' or $metadata['hostname'] = $headers['host'];

        $details = [];
        $request = self::request($headers);
        if ($request !== null) {
            $details['Request'] = \trim($request['method'] . ' ' . $request['host'] . $request['uri']);
            ($code = (int) ($headers['response-code'] ?? 0)) > 0 and $details['Response code'] = (string) $code;
        } else {
            ($command = self::command($headers)) === null or $details['Command'] = $command;
        }
        ($headers['probed-runtime'] ?? '') === '' or $details['Runtime'] = $headers['probed-runtime'];
        ($headers['probe-version'] ?? '') === '' or $details['Probe'] = $headers['probe-version'];
        $details === [] or $metadata['details'] = $details;

        return $metadata;
    }

    /**
     * @param array<string, string> $headers
     * @param array<array-key, mixed> $query
     *
     * @return array<non-empty-string, non-empty-string>
     */
    private static function tags(array $headers, array $query): array
    {
        $tags = [];
        /** @var mixed $title */
        $title = $query['profile_title'] ?? null;
        \is_string($title) && ($title = \trim($title)) !== '' and $tags['name'] = $title;

        $request = self::request($headers);
        $command = $request === null ? self::command($headers) : null;
        match (true) {
            $request !== null => $tags['request'] = \trim(
                $request['method'] . ' ' . \mb_strimwidth($request['uri'], 0, 120, '...'),
            ),
            $command !== null => $tags['command'] = \mb_strimwidth($command, 0, 120, '...'),
            default => null,
        };

        /** @var array<non-empty-string, non-empty-string> */
        return \array_filter($tags, static fn(string $value): bool => $value !== '');
    }

    /**
     * The HTTP request from the `context` header, a query string with `http_method`, `http_header_host`
     * and `http_uri`.
     *
     * @param array<string, string> $headers
     *
     * @return array{method: string, host: string, uri: string}|null Null for a command.
     */
    private static function request(array $headers): ?array
    {
        $context = self::context($headers);
        $method = $context['http_method'] ?? null;
        if (!\is_string($method) || $method === '') {
            return null;
        }

        $host = $context['http_header_host'] ?? '';
        $uri = $context['http_uri'] ?? '';

        return ['method' => $method, 'host' => \is_string($host) ? $host : '', 'uri' => \is_string($uri) ? $uri : ''];
    }

    /**
     * @param array<string, string> $headers
     *
     * @return non-empty-string|null The arguments of the command, `argv` of the `context` header.
     */
    private static function command(array $headers): ?string
    {
        $argv = self::context($headers)['argv'] ?? null;
        $args = [];
        /** @var mixed $arg */
        foreach (\is_array($argv) ? $argv : [] as $arg) {
            \is_string($arg) and $args[] = $arg;
        }
        $command = \implode(' ', $args);

        return $command === '' ? null : $command;
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array<array-key, string|array<array-key, mixed>>
     */
    private static function context(array $headers): array
    {
        \parse_str($headers['context'] ?? '', $context);

        /** @var array<array-key, string|array<array-key, mixed>> */
        return $context;
    }
}
