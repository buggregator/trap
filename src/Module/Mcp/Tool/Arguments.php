<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Mcp\Tool;

/**
 * Checks of the tool arguments, which come not validated against the input schema: only their names are.
 *
 * @internal
 */
final class Arguments
{
    /**
     * Fails on an argument the schema doesn't declare when it allows no other: a misspelt condition left out
     * would widen the call, as `clear_events` with `sinse` deleting every event.
     *
     * @param array<array-key, mixed> $arguments
     * @param array<array-key, mixed> $schema The input schema of the tool.
     *
     * @throws \InvalidArgumentException
     */
    public static function declared(array $arguments, array $schema, string $tool): void
    {
        if (($schema['additionalProperties'] ?? true) !== false) {
            return;
        }

        // Only `properties` names arguments: no input schema uses `patternProperties`
        /** @var mixed $properties */
        $properties = $schema['properties'] ?? [];
        $accepted = \is_array($properties) || \is_object($properties) ? \array_keys((array) $properties) : [];
        $accepted = \array_map(\strval(...), $accepted);
        $unknown = \array_diff(\array_map(\strval(...), \array_keys($arguments)), $accepted);
        if ($unknown === []) {
            return;
        }

        throw new \InvalidArgumentException(\sprintf(
            '%s `%s`; %s.',
            \count($unknown) === 1 ? 'Unknown argument' : 'Unknown arguments',
            \implode('`, `', $unknown),
            $accepted === [] ? "`$tool` takes no arguments" : "`$tool` takes: `" . \implode('`, `', $accepted) . '`',
        ));
    }

    /**
     * @param array<array-key, mixed> $arguments
     * @param int<0, max> $min
     * @param int<0, max> $max
     *
     * @return int<0, max>|null Null when the argument is missing.
     *
     * @throws \InvalidArgumentException
     */
    public static function integer(array $arguments, string $name, int $min = 0, int $max = \PHP_INT_MAX): ?int
    {
        /** @var mixed $value */
        $value = $arguments[$name] ?? null;
        if ($value === null) {
            return null;
        }

        // JSON has one number type: a client may send 10.0
        \is_float($value) && \floor($value) === $value and $value = (int) $value;
        \is_int($value) && $value >= $min && $value <= $max or throw new \InvalidArgumentException(\sprintf(
            '`%s` must be an integer from %d to %d.',
            $name,
            $min,
            $max,
        ));
        \assert($value >= 0);

        return $value;
    }

    /**
     * @param array<array-key, mixed> $arguments
     *
     * @return float|null Null when the argument is missing.
     *
     * @throws \InvalidArgumentException
     */
    public static function number(array $arguments, string $name, float $min = 0.0): ?float
    {
        /** @var mixed $value */
        $value = $arguments[$name] ?? null;
        if ($value === null) {
            return null;
        }

        (\is_int($value) || \is_float($value)) && \is_finite((float) $value) && $value >= $min
            or throw new \InvalidArgumentException(\sprintf('`%s` must be a number of at least %s.', $name, $min));

        return (float) $value;
    }

    /**
     * @param array<array-key, mixed> $arguments
     *
     * @return list<string> A string or a non-empty list of strings as a list; empty when the argument is missing.
     *
     * @throws \InvalidArgumentException
     */
    public static function strings(array $arguments, string $name): array
    {
        /** @var mixed $value */
        $value = $arguments[$name] ?? null;
        if ($value === null) {
            return [];
        }

        // An empty list would read as no condition: a filter of `clear_events` would delete every event
        $values = \is_string($value) ? [$value] : $value;
        \is_array($values) && $values !== [] && \array_is_list($values) && \array_filter($values, 'is_string') === $values
            or throw new \InvalidArgumentException("`$name` must be a string or a non-empty list of strings.");
        /** @var list<string> $values */

        return $values;
    }

    /**
     * @param array<array-key, mixed> $arguments
     * @param non-empty-list<non-empty-string> $choices The first one is the default.
     *
     * @return non-empty-string
     *
     * @throws \InvalidArgumentException
     */
    public static function choice(array $arguments, string $name, array $choices): string
    {
        /** @var mixed $value */
        $value = $arguments[$name] ?? $choices[0];
        \is_string($value) && \in_array($value, $choices, true) or throw new \InvalidArgumentException(\sprintf(
            '`%s` must be one of: %s.',
            $name,
            \implode(', ', $choices),
        ));
        /** @var non-empty-string $value */

        return $value;
    }

    /**
     * @param array<array-key, mixed> $arguments
     *
     * @throws \InvalidArgumentException
     */
    public static function boolean(array $arguments, string $name): bool
    {
        /** @var mixed $value */
        $value = $arguments[$name] ?? false;
        \is_bool($value) or throw new \InvalidArgumentException("`$name` must be a boolean.");

        return $value;
    }
}
