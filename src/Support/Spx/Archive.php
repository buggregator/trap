<?php

declare(strict_types=1);

namespace Buggregator\Trap\Support\Spx;

/**
 * An SPX full report packed into one file: a POSIX tar archive of the `<key>.json` metadata, then
 * the `<key>.txt.gz` trace, named as SPX names them. Extracted into `spx.data_dir`, it shows up in the SPX web UI.
 *
 * A name over the 100 bytes of a ustar header, from a long host name in the key, goes to a pax header before
 * the file: the ustar prefix field can't hold it, as tar tools join it to the name with a `/`.
 * Reading takes regular files, their pax `path` and a ustar prefix; other entries are rejected.
 *
 * @internal
 */
final class Archive
{
    public const BLOCK = 512;

    /** Limit of a file name in bytes. */
    public const MAX_NAME = 4096;

    private const NAME_SIZE = 100;

    /** Limit of a pax header in bytes: a name and its record. */
    private const MAX_PAX = 8192;

    /**
     * @param array<non-empty-string, string> $files Contents by names, in order.
     *
     * @throws \InvalidArgumentException When a name is longer than {@see self::MAX_NAME}.
     */
    public static function pack(array $files): string
    {
        $parts = [];
        foreach ($files as $name => $content) {
            $parts[$name] = [\strlen($content), [$content]];
        }

        return \implode('', \iterator_to_array(self::stream($parts)[1], false));
    }

    /**
     * Packs files without holding their contents: a content comes in chunks of the size told.
     *
     * @param array<non-empty-string, array{int<0, max>, iterable<string>}> $files Sizes and contents by names, in order.
     *
     * @return array{int<0, max>, \Generator<int, string>} The size of the archive and its chunks.
     *
     * @throws \InvalidArgumentException When a name is longer than {@see self::MAX_NAME}.
     */
    public static function stream(array $files): array
    {
        $size = 2 * self::BLOCK;
        $headers = [];
        foreach ($files as $name => [$length]) {
            \strlen($name) > self::MAX_NAME and throw new \InvalidArgumentException(
                'The file name is longer than ' . self::MAX_NAME . ' bytes.',
            );
            $header = '';
            if (\strlen($name) > self::NAME_SIZE) {
                $record = self::paxRecord('path', $name);
                $header = self::header('PaxHeader/' . \md5($name), \strlen($record), 'x')
                    . $record . self::padding(\strlen($record));
                // Readers that know no pax get the start of the name
                $name = \substr($name, 0, self::NAME_SIZE);
            }
            $headers[] = $header .= self::header($name, $length, '0');
            $size += \strlen($header) + $length + self::paddingOf($length);
        }

        $chunks = static function () use ($files, $headers): \Generator {
            foreach (\array_values($files) as $i => [$length, $content]) {
                yield $headers[$i];
                $written = 0;
                foreach ($content as $chunk) {
                    $written += \strlen($chunk);
                    yield $chunk;
                }
                $written === $length or throw new \RuntimeException(
                    "A file of the archive has $written bytes, not the $length told.",
                );
                yield self::padding($length);
            }
            yield \str_repeat("\0", 2 * self::BLOCK);
        };

        return [$size, $chunks()];
    }

    /**
     * Reads the header of the next file, and the pax header before it.
     *
     * @param \Closure(int<0, max>): string $read Reads the next bytes, as many as asked unless the data ends.
     *        The content of the file and its padding, see {@see self::paddingOf()}, are left to read.
     *
     * @return array{non-empty-string, int<0, max>}|null The name and size of the file; null for the end of the archive.
     *
     * @throws \InvalidArgumentException When the data is not a tar archive of regular files.
     */
    public static function next(\Closure $read): ?array
    {
        $path = null;
        while (true) {
            $header = self::parse($read(self::BLOCK));
            if ($header === null) {
                return null;
            }

            [$name, $size, $type] = $header;
            if ($type === 'x') {
                $size <= self::MAX_PAX or throw new \InvalidArgumentException('The pax header is too large.');
                $path = self::paxPath($read($size)) ?? $path;
                $read(self::paddingOf($size));
                continue;
            }

            \in_array($type, ['0', "\0"], true)
                or throw new \InvalidArgumentException('The archive has an entry that is not a regular file.');

            return [$path ?? $name, $size];
        }
    }

    /**
     * @param int<0, max> $size
     *
     * @return int<0, 511> Bytes that pad an entry of the size to a whole block.
     */
    public static function paddingOf(int $size): int
    {
        return (self::BLOCK - $size % self::BLOCK) % self::BLOCK;
    }

    /**
     * @return array{non-empty-string, int<0, max>, string}|null The name with its prefix, the size and the type;
     *         null for the end of the archive.
     */
    private static function parse(string $block): ?array
    {
        \strlen($block) === self::BLOCK or throw new \InvalidArgumentException('The archive is truncated.');
        if (\trim($block, "\0") === '') {
            return null;
        }

        \substr($block, 257, 5) === 'ustar' or throw new \InvalidArgumentException('Not a tar archive.');
        $sum = \array_sum(\array_map(\ord(...), \str_split(\substr_replace($block, '        ', 148, 8))));
        \octdec(\trim(\substr($block, 148, 8), "\0 ")) === $sum
            or throw new \InvalidArgumentException('Invalid tar header checksum.');

        $name = \rtrim(\substr($block, 0, self::NAME_SIZE), "\0");
        $prefix = \rtrim(\substr($block, 345, 155), "\0");
        $prefix === '' or $name = "$prefix/$name";
        $name === '' and throw new \InvalidArgumentException('The archive has an entry with no name.');
        $size = \trim(\substr($block, 124, 12), "\0 ");
        \preg_match('/^[0-7]{1,11}$/', $size) === 1 or throw new \InvalidArgumentException('Invalid tar entry size.');

        return [$name, \max(0, (int) \octdec($size)), $block[156]];
    }

    /**
     * @return non-empty-string|null The `path` of pax records `<length> <key>=<value>\n`.
     */
    private static function paxPath(string $records): ?string
    {
        $path = null;
        for ($offset = 0; $offset < \strlen($records); $offset += $length) {
            $space = \strpos($records, ' ', $offset);
            $space === false and throw new \InvalidArgumentException('Invalid pax header.');
            $length = (int) \substr($records, $offset, $space - $offset);
            $length > 0 && $offset + $length <= \strlen($records)
                or throw new \InvalidArgumentException('Invalid pax header.');
            [$key, $value] = \explode('=', \substr($records, $space + 1, $length - ($space - $offset) - 2), 2) + [1 => ''];
            $key === 'path' && $value !== '' and $path = $value;
        }

        return $path;
    }

    private static function paxRecord(string $key, string $value): string
    {
        // The length counts its own digits
        $length = \strlen(" $key=$value\n");
        $length += \strlen((string) ($length + \strlen((string) $length)));

        return "$length $key=$value\n";
    }

    private static function padding(int $size): string
    {
        return \str_repeat("\0", self::paddingOf(\max(0, $size)));
    }

    private static function header(string $name, int $size, string $type): string
    {
        $header = \str_pad($name, self::NAME_SIZE, "\0")
            . "0000644\0"
            . "0000000\0"
            . "0000000\0"
            . \sprintf('%011o', $size) . "\0"
            . \sprintf('%011o', \time()) . "\0"
            . '        '
            . $type
            . \str_repeat("\0", 100)
            . "ustar\0" . '00';
        $header = \str_pad($header, self::BLOCK, "\0");
        $sum = \array_sum(\array_map(\ord(...), \str_split($header)));

        return \substr_replace($header, \sprintf('%06o', $sum) . "\0 ", 148, 8);
    }
}
