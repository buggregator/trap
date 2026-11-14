<?php

declare(strict_types=1);

namespace Buggregator\Trap\Client\Profiler\Internal;

use Buggregator\Trap\Client\Profiler\Format;
use Buggregator\Trap\Client\Profiler\Metadata;

/**
 * Data of a stopped profiler with an encoder per supported format.
 *
 * @internal
 */
final class Recorded
{
    /** Bytes of memory kept free besides a document joined into a string. */
    public const HEADROOM = 16 * 1024 * 1024;

    /** Replaces the memory left, in bytes, for tests. */
    public static ?int $freeMemory = null;

    /** @var array<string, string> */
    private array $encoded = [];

    /**
     * @param non-empty-array<value-of<Format>, \Closure(?Metadata): (string|array{int<0, max>, iterable<string>})> $encoders
     *        The native format first. An encoder of a large document gives its size and chunks, for destinations
     *        that write it on the go, see {@see self::stream()}.
     * @param bool $empty Nothing was recorded: no samples or no calls.
     * @param \Closure(bool): void|null $settle Takes whether the profile was delivered, once its destinations ran.
     */
    public function __construct(
        private readonly array $encoders,
        private readonly bool $empty = false,
        private readonly ?\Closure $settle = null,
    ) {}

    /**
     * @param list<Format> $formats
     */
    public static function unsupported(Format $format, array $formats): \InvalidArgumentException
    {
        return new \InvalidArgumentException(\sprintf(
            'The profile can not be encoded as %s; available formats: %s.',
            $format->name,
            $formats === [] ? 'none' : \implode(', ', \array_map(static fn(Format $f): string => $f->name, $formats)),
        ));
    }

    /**
     * Whether nothing was recorded; such a profile is not delivered.
     */
    public function isEmpty(): bool
    {
        return $this->empty;
    }

    /**
     * @return non-empty-list<Format>
     */
    public function formats(): array
    {
        return \array_map(Format::from(...), \array_keys($this->encoders));
    }

    /**
     * @param Metadata|null $metadata Of the profile, for the encoders that write it into the document;
     *        a profile has the same metadata on every call.
     *
     * @throws \InvalidArgumentException When the format is not supported.
     */
    public function encode(Format $format, ?Metadata $metadata = null): string
    {
        if (isset($this->encoded[$format->value])) {
            return $this->encoded[$format->value];
        }

        $encoder = $this->encoders[$format->value] ?? throw self::unsupported($format, $this->formats());
        $document = $encoder($metadata);

        if (\is_string($document)) {
            return $this->encoded[$format->value] = $document;
        }

        // Joined, a document of chunks takes its whole size in memory, which the profiled process may lack
        $free = self::$freeMemory ?? $this->freeMemory();
        $free === null || $free >= $document[0] + self::HEADROOM or throw new \RuntimeException(\sprintf(
            'The %s document of %d bytes would not fit the memory left; send or write it instead.',
            $format->name,
            $document[0],
        ));

        return $this->encoded[$format->value] = \implode('', \iterator_to_array($document[1], false));
    }

    /**
     * Tells the recorder whether any destination took the profile.
     */
    public function settle(bool $delivered): void
    {
        !$this->settle instanceof \Closure or ($this->settle)($delivered);
    }

    /**
     * The document in chunks, not held whole when its encoder gives them so.
     *
     * @return array{int<0, max>, iterable<string>} The size of the document and its chunks.
     *
     * @throws \InvalidArgumentException When the format is not supported.
     */
    public function stream(Format $format, ?Metadata $metadata = null): array
    {
        if (isset($this->encoded[$format->value])) {
            return [\strlen($this->encoded[$format->value]), [$this->encoded[$format->value]]];
        }

        $encoder = $this->encoders[$format->value] ?? throw self::unsupported($format, $this->formats());
        $document = $encoder($metadata);
        if (\is_string($document)) {
            $this->encoded[$format->value] = $document;
            return [\strlen($document), [$document]];
        }

        return $document;
    }

    /**
     * @return int<0, max>|null Null with no memory limit.
     */
    private function freeMemory(): ?int
    {
        $raw = \ini_get('memory_limit');
        $limit = \is_string($raw) && $raw !== '' ? \ini_parse_quantity($raw) : -1;

        return $limit <= 0 ? null : \max(0, $limit - \memory_get_usage());
    }
}
