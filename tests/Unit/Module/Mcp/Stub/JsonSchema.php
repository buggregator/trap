<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Mcp\Stub;

use Buggregator\Trap\Support\Json;

/**
 * Checks a tool result against its output schema, for the keywords the tools use: `type`, `properties`,
 * `required`, `additionalProperties`, `items`, `minimum`, `enum` and `anyOf`.
 */
final class JsonSchema
{
    /**
     * The value is checked as a client decodes it: as its JSON, where an empty PHP array is a list.
     *
     * @return list<string> Violations with their JSON paths; empty when the value conforms.
     */
    public static function errors(mixed $value, array $schema): array
    {
        return self::check(\json_decode(Json::encode($value), false, 512, \JSON_THROW_ON_ERROR), $schema, '$');
    }

    /**
     * @return list<string>
     */
    private static function check(mixed $value, array $schema, string $path): array
    {
        if (isset($schema['type'])) {
            $types = (array) $schema['type'];
            if (!\in_array(self::type($value), $types, true) && !(\is_int($value) && \in_array('number', $types, true))) {
                return ["$path: " . self::type($value) . ' is not ' . \implode('|', $types)];
            }
        }

        if (isset($schema['anyOf'])) {
            foreach ($schema['anyOf'] as $option) {
                if (self::check($value, $option, $path) === []) {
                    return [];
                }
            }

            return ["$path: matches no `anyOf` schema"];
        }

        $errors = [];
        if (isset($schema['enum']) && !\in_array($value, $schema['enum'], true)) {
            $errors[] = "$path: not in enum";
        }

        if (isset($schema['minimum']) && \is_numeric($value) && $value < $schema['minimum']) {
            $errors[] = "$path: below {$schema['minimum']}";
        }

        if ($value instanceof \stdClass) {
            $properties = (array) ($schema['properties'] ?? []);
            foreach ($schema['required'] ?? [] as $key) {
                \property_exists($value, $key) or $errors[] = "$path: no `$key`";
            }

            foreach (\get_object_vars($value) as $key => $item) {
                if (isset($properties[$key])) {
                    $errors = [...$errors, ...self::check($item, $properties[$key], "$path.$key")];
                } elseif (($schema['additionalProperties'] ?? true) === false) {
                    $errors[] = "$path: unexpected `$key`";
                } elseif (\is_array($schema['additionalProperties'] ?? null)) {
                    $errors = [...$errors, ...self::check($item, $schema['additionalProperties'], "$path.$key")];
                }
            }
        }

        if (\is_array($value) && isset($schema['items'])) {
            foreach ($value as $i => $item) {
                $errors = [...$errors, ...self::check($item, $schema['items'], "{$path}[$i]")];
            }
        }

        return $errors;
    }

    private static function type(mixed $value): string
    {
        return match (true) {
            $value instanceof \stdClass => 'object',
            \is_array($value) => 'array',
            \is_string($value) => 'string',
            \is_int($value) => 'integer',
            \is_float($value) => 'number',
            \is_bool($value) => 'boolean',
            default => 'null',
        };
    }
}
