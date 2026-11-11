<?php

declare(strict_types=1);

namespace Buggregator\Trap\Client\Profiler\Internal;

use Buggregator\Trap\Client\Profiler\Format;

/**
 * Data of a stopped profiler with an encoder per supported format.
 *
 * @internal
 */
final class Recorded
{
    /** @var array<string, string> */
    private array $encoded = [];

    /**
     * @param non-empty-array<value-of<Format>, \Closure(): string> $encoders The native format first.
     * @param bool $empty Nothing was recorded: no samples or no calls.
     */
    public function __construct(
        private readonly array $encoders,
        private readonly bool $empty = false,
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
     * @throws \InvalidArgumentException When the format is not supported.
     */
    public function encode(Format $format): string
    {
        $encoder = $this->encoders[$format->value] ?? throw self::unsupported($format, $this->formats());

        return $this->encoded[$format->value] ??= $encoder();
    }
}
