<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Event;

/**
 * Values an event is filtered by in the UI: the machine, environment, user, channel, level, release,
 * trace ID and tags, as buggregator/server indexes them.
 *
 * @internal
 */
final class Facets
{
    public const HOST = 'host';
    public const ENV = 'env';
    public const USER = 'user';
    public const CHANNEL = 'channel';
    public const LEVEL = 'level';
    public const RELEASE = 'release';
    public const TRACE = 'trace';
    public const TAG_PREFIX = 'tag:';

    /** Longer values are cut: request IDs as tags would not help a filter. */
    private const MAX_VALUE_LENGTH = 200;

    private const MAX_TAGS = 50;

    /** @var array<non-empty-string, list<non-empty-string>> */
    private array $values = [];

    private int $tags = 0;

    /**
     * Whether a key can be filtered by: a known one, or `tag:<name>`.
     *
     * @psalm-assert-if-true non-empty-string $key
     */
    public static function isKey(string $key): bool
    {
        return \in_array($key, [self::HOST, self::ENV, self::USER, self::CHANNEL, self::LEVEL, self::RELEASE, self::TRACE], true)
            || \str_starts_with($key, self::TAG_PREFIX) && \strlen($key) > \strlen(self::TAG_PREFIX);
    }

    /**
     * The stored form of a value: Monolog's `WARNING` and Sentry's `warning` are one level.
     */
    public static function normalize(string $key, string $value): string
    {
        $value = \trim($value);
        return $key === self::LEVEL ? \strtolower($value) : $value;
    }

    /**
     * The trace ID of a request from the headers Sentry and OpenTelemetry SDKs add to outgoing calls.
     *
     * @param callable(non-empty-string): string $header A header line by its name, empty when absent.
     */
    public static function traceFromHeaders(callable $header): string
    {
        $id = \explode('-', \trim($header('Sentry-Trace')), 2)[0];
        if (self::isTraceId($id)) {
            return \strtolower($id);
        }

        $id = \explode('-', \trim($header('Traceparent')))[1] ?? '';
        if (self::isTraceId($id)) {
            return \strtolower($id);
        }

        $id = \trim($header('X-Trace-Id'));
        return self::isTraceId($id) ? \strtolower($id) : '';
    }

    /**
     * @param mixed $value A scalar; anything else adds nothing.
     */
    public function add(string $key, mixed $value): self
    {
        $value = match (true) {
            \is_string($value) => $value,
            \is_int($value), \is_float($value) => (string) $value,
            \is_bool($value) => $value ? 'true' : 'false',
            default => '',
        };
        $value = self::normalize($key, $value);
        if ($key === '' || $value === '') {
            return $this;
        }

        if (\strlen($value) > self::MAX_VALUE_LENGTH) {
            $value = \substr($value, 0, self::MAX_VALUE_LENGTH);
            // Not in the middle of a UTF-8 character
            for ($i = 0; $i < 3 && \preg_match('//u', $value) !== 1; ++$i) {
                $value = \substr($value, 0, -1);
            }
            if ($value === '') {
                return $this;
            }
        }

        if (\in_array($value, $this->values[$key] ?? [], true)) {
            return $this;
        }

        if (\str_starts_with($key, self::TAG_PREFIX)) {
            if ($this->tags >= self::MAX_TAGS) {
                return $this;
            }
            ++$this->tags;
        }

        $this->values[$key][] = $value;
        return $this;
    }

    /**
     * @param mixed $tags `{"name": "value"}` or `[["name", "value"]]`: Sentry SDKs send both.
     */
    public function addTags(mixed $tags): self
    {
        if (!\is_array($tags)) {
            return $this;
        }

        $pairs = \array_is_list($tags) ? $tags : \array_map(null, \array_keys($tags), $tags);
        /** @var mixed $pair */
        foreach ($pairs as $pair) {
            /** @var mixed $name */
            $name = \is_array($pair) && \count($pair) === 2 ? $pair[0] ?? null : null;
            if (!\is_string($name)) {
                continue;
            }
            \assert(\is_array($pair));
            $name = \trim($name);
            $name === '' || \strlen($name) > self::MAX_VALUE_LENGTH
                or $this->add(self::TAG_PREFIX . $name, $pair[1] ?? null);
        }

        return $this;
    }

    /**
     * @return array<non-empty-string, list<non-empty-string>>
     */
    public function toArray(): array
    {
        return $this->values;
    }

    /**
     * 32 hex digits, not all zero.
     */
    private static function isTraceId(string $id): bool
    {
        return \preg_match('/^[0-9a-f]{32}$/i', $id) === 1 && \trim($id, '0') !== '';
    }
}
