<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Client\Profiler\Stub;

/**
 * Reads back the pprof `perftools.profiles.Profile` messages the client writes, failing on anything
 * the writer must not produce: other wire types, unpacked repeated scalars, truncated data.
 *
 * @psalm-type Decoded = array{
 *     gzip: bool,
 *     strings: list<string>,
 *     sample_type: list<array{string, string}>,
 *     samples: list<array{locations: list<int>, values: list<int>}>,
 *     mappings: array<int, array{filename: string, has_functions: bool, has_filenames: bool}>,
 *     locations: array<int, list<int>>,
 *     location_mappings: array<int, int>,
 *     functions: array<int, array{name: string, system_name: string, filename: string}>,
 *     time_nanos: int,
 *     duration_nanos: int,
 *     period_type: array{string, string}|null,
 *     period: int,
 *     default_sample_type: string,
 * }
 */
final class PprofDecoder
{
    /**
     * @return Decoded
     */
    public static function profile(string $data): array
    {
        $gzip = \str_starts_with($data, "\x1f\x8b");
        if ($gzip) {
            $data = \gzdecode($data);
            $data === false and throw new \UnexpectedValueException('Invalid gzip data.');
        }

        $fields = self::fields($data, [1 => 2, 2 => 2, 3 => 2, 4 => 2, 5 => 2, 6 => 2, 9 => 0, 10 => 0, 11 => 2, 12 => 0, 14 => 0]);

        /** @var list<string> $strings */
        $strings = $fields[6] ?? [];
        $string = static fn(int $index): string => $strings[$index]
            ?? throw new \UnexpectedValueException("No string {$index}.");
        $valueType = static function (string $message) use ($string): array {
            $type = self::fields($message, [1 => 0, 2 => 0]);
            return [$string(self::single($type, 1)), $string(self::single($type, 2))];
        };

        $samples = [];
        foreach ($fields[2] ?? [] as $message) {
            $sample = self::fields((string) $message, [1 => 2, 2 => 2]);
            $samples[] = [
                'locations' => self::varints((string) ($sample[1][0] ?? '')),
                'values' => self::varints((string) ($sample[2][0] ?? '')),
            ];
        }

        $mappings = [];
        foreach ($fields[3] ?? [] as $message) {
            $mapping = self::fields((string) $message, [1 => 0, 5 => 0, 7 => 0, 8 => 0]);
            $id = self::single($mapping, 1);
            isset($mappings[$id]) and throw new \UnexpectedValueException("Duplicate mapping {$id}.");
            $mappings[$id] = [
                'filename' => $string(self::single($mapping, 5)),
                'has_functions' => self::single($mapping, 7) === 1,
                'has_filenames' => self::single($mapping, 8) === 1,
            ];
        }

        $locations = [];
        $locationMappings = [];
        foreach ($fields[4] ?? [] as $message) {
            $location = self::fields((string) $message, [1 => 0, 2 => 0, 4 => 2]);
            $lines = [];
            foreach ($location[4] ?? [] as $line) {
                $lines[] = self::single(self::fields((string) $line, [1 => 0]), 1);
            }
            $id = self::single($location, 1);
            isset($locations[$id]) and throw new \UnexpectedValueException("Duplicate location {$id}.");
            $locations[$id] = $lines;
            $mapping = self::single($location, 2);
            isset($mappings[$mapping]) or throw new \UnexpectedValueException("No mapping {$mapping}.");
            $locationMappings[$id] = $mapping;
        }

        $functions = [];
        foreach ($fields[5] ?? [] as $message) {
            $function = self::fields((string) $message, [1 => 0, 2 => 0, 3 => 0, 4 => 0]);
            $id = self::single($function, 1);
            isset($functions[$id]) and throw new \UnexpectedValueException("Duplicate function {$id}.");
            $functions[$id] = [
                'name' => $string(self::single($function, 2)),
                'system_name' => $string(self::single($function, 3)),
                'filename' => $string(self::single($function, 4)),
            ];
        }

        return [
            'gzip' => $gzip,
            'strings' => $strings,
            'sample_type' => \array_map(static fn(int|string $m): array => $valueType((string) $m), $fields[1] ?? []),
            'samples' => $samples,
            'mappings' => $mappings,
            'locations' => $locations,
            'location_mappings' => $locationMappings,
            'functions' => $functions,
            'time_nanos' => self::single($fields, 9),
            'duration_nanos' => self::single($fields, 10),
            'period_type' => isset($fields[11]) ? $valueType((string) $fields[11][0]) : null,
            'period' => self::single($fields, 12),
            'default_sample_type' => $string(self::single($fields, 14)),
        ];
    }

    /**
     * Stacks root first as `a;b;c`, each with its values joined by `/`; the stacks must not repeat.
     *
     * @param Decoded $profile
     *
     * @return array<string, string>
     */
    public static function stacks(array $profile): array
    {
        $stacks = [];
        foreach ($profile['samples'] as $sample) {
            $names = [];
            foreach ($sample['locations'] as $location) {
                foreach ($profile['locations'][$location] ?? throw new \UnexpectedValueException("No location {$location}.") as $function) {
                    $names[] = ($profile['functions'][$function] ?? throw new \UnexpectedValueException("No function {$function}."))['name'];
                }
            }
            $key = \implode(';', \array_reverse($names));
            isset($stacks[$key]) and throw new \UnexpectedValueException("Duplicate stack {$key}.");
            $stacks[$key] = \implode('/', $sample['values']);
        }

        return $stacks;
    }

    /**
     * @return list<int>
     */
    public static function varints(string $data): array
    {
        $values = [];
        $offset = 0;
        while ($offset < \strlen($data)) {
            $values[] = self::varint($data, $offset);
        }

        return $values;
    }

    /**
     * @param array<int, 0|2> $wireTypes Of every allowed field.
     *
     * @return array<int, list<int|string>> Values by field number.
     */
    private static function fields(string $data, array $wireTypes): array
    {
        $fields = [];
        $offset = 0;
        while ($offset < \strlen($data)) {
            $tag = self::varint($data, $offset);
            $field = $tag >> 3;
            $wireType = $tag & 7;
            ($wireTypes[$field] ?? null) === $wireType
            or throw new \UnexpectedValueException("Unexpected field {$field} of wire type {$wireType}.");

            if ($wireType === 0) {
                $fields[$field][] = self::varint($data, $offset);
                continue;
            }

            $length = self::varint($data, $offset);
            $offset + $length <= \strlen($data) or throw new \UnexpectedValueException('Truncated field.');
            $fields[$field][] = \substr($data, $offset, $length);
            $offset += $length;
        }

        return $fields;
    }

    /**
     * @param array<int, list<int|string>> $fields
     */
    private static function single(array $fields, int $field): int
    {
        \count($fields[$field] ?? []) <= 1 or throw new \UnexpectedValueException("Repeated field {$field}.");

        return (int) ($fields[$field][0] ?? 0);
    }

    private static function varint(string $data, int &$offset): int
    {
        $value = 0;
        for ($shift = 0; $shift < 70; $shift += 7) {
            $offset < \strlen($data) or throw new \UnexpectedValueException('Truncated varint.');
            $byte = \ord($data[$offset++]);
            $shift < 64 and $value |= ($byte & 0x7F) << $shift;
            if ($byte < 0x80) {
                return $value;
            }
        }

        throw new \UnexpectedValueException('A varint is longer than 10 bytes.');
    }
}
