<?php

declare(strict_types=1);

namespace Buggregator\Trap\Support;

/**
 * What raw bytes look like, guessed from their start: a protocol a client speaks to the wrong port, a file format,
 * a serialization. A guess, not a parse: only the signature and a few header fields are checked.
 *
 * @internal
 * @psalm-internal Buggregator\Trap
 */
final class BinaryFormat
{
    /** Bytes of the start {@see guess()} reads. */
    public const HEAD = 4096;

    /**
     * @param non-empty-string $id Stable: `tls`, `gzip`, `text`.
     * @param non-empty-string $name For a reader: `TLS ClientHello for example.com`.
     * @param string $hint What it means for the developer; may be empty.
     * @param non-empty-string $extension Of a file of these bytes, without the dot.
     */
    private function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $hint,
        public readonly string $extension = 'bin',
    ) {}

    /**
     * @param string $head The first {@see HEAD} bytes, or all the bytes when there are fewer: a shorter head is
     *        taken for the whole data.
     *
     * @return self|null Null when nothing matches.
     */
    public static function guess(string $head): ?self
    {
        $head = \substr($head, 0, self::HEAD);
        if ($head === '') {
            return null;
        }

        return self::protocol($head)
            ?? self::file($head)
            ?? self::serialized($head)
            ?? self::text($head)
            ?? self::messagePack($head)
            ?? self::protobuf($head);
    }

    /**
     * Shannon entropy: near 8 for compressed or encrypted bytes, 4 to 5 for English text.
     *
     * @return float Bits per byte, 0 to 8.
     */
    public static function entropy(string $bytes): float
    {
        $length = \strlen($bytes);
        if ($length === 0) {
            return 0.0;
        }

        $entropy = 0.0;
        foreach (\count_chars($bytes, 1) as $count) {
            $p = (float) $count / (float) $length;
            $entropy -= $p * \log($p, 2);
        }

        return \max(0.0, $entropy);
    }

    /**
     * Clients of network protocols, which speak first.
     */
    private static function protocol(string $head): ?self
    {
        if (\preg_match('/^\x16\x03[\x00-\x04]/', $head) === 1) {
            if (($head[5] ?? '') !== "\x01") {
                return new self('tls', 'TLS handshake', 'A TLS peer on a port without TLS.');
            }

            $host = self::serverName($head);
            return new self(
                'tls',
                'TLS ClientHello' . ($host === null ? '' : " for $host"),
                'A client speaks TLS to a port without it: turn TLS on for the port, or send plain text.',
            );
        }

        return match (true) {
            \str_starts_with($head, "PRI * HTTP/2.0\r\n\r\nSM\r\n\r\n") => new self(
                'http2',
                'HTTP/2 connection preface',
                'A client speaks HTTP/2 without TLS (h2c with prior knowledge); Trap takes HTTP/1.1.',
            ),
            \str_starts_with($head, "\r\n\r\n\x00\r\nQUIT\n"), \str_starts_with($head, 'PROXY ') => new self(
                'proxy',
                'PROXY protocol header',
                'A proxy or load balancer puts the client address in front of the traffic: turn it off for this upstream.',
            ),
            \str_starts_with($head, "\x00\x00\x00\x08\x04\xd2\x16\x2f"),
            \str_starts_with($head, "\x00\x00\x00\x08\x04\xd2\x16\x30"),
            \strlen($head) >= 8 && \substr($head, 4, 4) === "\x00\x03\x00\x00" => new self(
                'postgres',
                'PostgreSQL startup',
                'A PostgreSQL client: check the host and port of its connection.',
            ),
            \strlen($head) >= 8 && \substr($head, 0, 2) === "\x01\x01" && \substr($head, 4, 2) === "\x00\x08" => new self(
                'fastcgi',
                'FastCGI request',
                'A web server passes PHP requests here as to PHP-FPM: check its FastCGI address.',
            ),
            \preg_match('/^\*\d{1,9}\r\n\$\d{1,9}\r\n([A-Za-z]{1,32})/', $head, $command) === 1 => new self(
                'redis',
                'Redis command ' . \strtoupper($command[1]),
                'A Redis client: check the host and port of its connection.',
            ),
            \str_starts_with($head, 'SSH-') => new self('ssh', 'SSH handshake', 'An SSH client.'),
            \str_starts_with($head, "AMQP\x00") => new self(
                'amqp',
                'AMQP protocol header',
                'An AMQP client, as of RabbitMQ: check the host and port of its connection.',
            ),
            \strlen($head) >= 16 && \in_array(\substr($head, 12, 4), ["\xdd\x07\x00\x00", "\xd4\x07\x00\x00"], true) => new self(
                'mongodb',
                'MongoDB message',
                'A MongoDB client: check the host and port of its connection.',
            ),
            default => null,
        };
    }

    private static function file(string $head): ?self
    {
        return match (true) {
            \str_starts_with($head, "\x1f\x8b") => new self('gzip', 'gzip data', 'Compressed: download and gunzip it.', 'gz'),
            \str_starts_with($head, "\x28\xb5\x2f\xfd") => new self('zstd', 'Zstandard data', 'Compressed.', 'zst'),
            \str_starts_with($head, "\xfd7zXZ\x00") => new self('xz', 'xz data', 'Compressed.', 'xz'),
            \preg_match('/^BZh[1-9]1AY&SY/', $head) === 1 => new self('bzip2', 'bzip2 data', 'Compressed.', 'bz2'),
            \str_starts_with($head, "PK\x03\x04") => new self('zip', 'ZIP archive', '', 'zip'),
            \str_starts_with($head, "\x89PNG\r\n\x1a\n") => new self('png', 'PNG image', '', 'png'),
            \str_starts_with($head, "\xff\xd8\xff") => new self('jpeg', 'JPEG image', '', 'jpg'),
            \preg_match('/^GIF8[79]a/', $head) === 1 => new self('gif', 'GIF image', '', 'gif'),
            \strlen($head) >= 12 && \str_starts_with($head, 'RIFF') && \substr($head, 8, 4) === 'WEBP'
                => new self('webp', 'WebP image', '', 'webp'),
            \str_starts_with($head, '%PDF-') => new self('pdf', 'PDF document', '', 'pdf'),
            \strlen($head) >= 2 && $head[0] === "\x78" && \in_array($head[1], ["\x01", "\x5e", "\x9c", "\xda"], true)
                => new self('zlib', 'zlib data, probably', 'Compressed with deflate in a zlib wrapper.', 'zz'),
            default => null,
        };
    }

    private static function serialized(string $head): ?self
    {
        return match (true) {
            \preg_match('/^(?:[aOC]:\d+:[{"]|s:\d+:"|i:-?\d+;|d:-?[\d.EINAF+-]+;|b:[01];|N;)/', $head) === 1
                => new self('php-serialize', 'PHP serialize() data', 'Read it with unserialize(); never on untrusted bytes.'),
            \str_starts_with($head, "\x00\x00\x00\x02") && \strlen($head) > 4
                => new self('igbinary', 'igbinary data, probably', 'PHP data serialized by igbinary: read it with igbinary_unserialize().'),
            \str_starts_with($head, "\xac\xed\x00\x05") => new self('java-serialized', 'Java serialized object', ''),
            default => null,
        };
    }

    private static function text(string $head): ?self
    {
        $text = $head;
        // A character of UTF-8 may be cut at the end of a full head: up to 3 bytes of it
        for ($cut = 1; $cut <= 3 && \strlen($head) >= self::HEAD && !\mb_check_encoding($text, 'UTF-8'); ++$cut) {
            $text = \substr($head, 0, -$cut);
        }
        if (!\mb_check_encoding($text, 'UTF-8') || \preg_match('/[\x00-\x08\x0b\x0e-\x1f\x7f]/', $text) === 1) {
            return null;
        }

        $trimmed = \trim($text);
        if (($trimmed[0] ?? '') === '{' || ($trimmed[0] ?? '') === '[') {
            try {
                Json::decode($trimmed);
                return new self('json', 'JSON', 'No module took this JSON: check the port and the format of the sender.', 'json');
            } catch (\JsonException) {
                // A JSON cut by the head, or not JSON
            }
        }

        $ascii = \preg_match('/[\x80-\xff]/', $text) !== 1;

        return new self('text', $ascii ? 'ASCII text' : 'UTF-8 text', 'Text in a protocol no module recognises.', 'txt');
    }

    /**
     * The whole head must read as MessagePack values: a map or an array first, the only reasonable top level.
     */
    private static function messagePack(string $head): ?self
    {
        $first = \ord($head[0]);
        $container = $first >= 0x81 && $first <= 0x8f || $first >= 0x91 && $first <= 0x9f
            || \in_array($first, [0xdc, 0xdd, 0xde, 0xdf], true);
        if (!$container || \strlen($head) < 3) {
            return null;
        }

        $offset = 0;
        $length = \strlen($head);
        while ($offset < $length) {
            $offset = self::skipMessagePack($head, $offset, 0);
            if ($offset === null) {
                return null;
            }
        }

        return new self('msgpack', 'MessagePack data, probably', 'Decode it with a MessagePack reader.', 'msgpack');
    }

    /**
     * @return int|null The offset after the value; past the end when the head cuts it; null for a byte that starts
     *         no value, or nesting too deep.
     */
    private static function skipMessagePack(string $data, int $offset, int $depth): ?int
    {
        if ($depth > 32) {
            return null;
        }
        if ($offset >= \strlen($data)) {
            return $offset;
        }

        $byte = \ord($data[$offset]);
        $uint = static function (int $at, int $bytes) use ($data): int {
            $value = 0;
            for ($i = 0; $i < $bytes; ++$i) {
                $value = $value << 8 | \ord($data[$at + $i] ?? "\x00");
            }
            return $value;
        };
        $items = static function (int $at, int $count, int $perItem) use ($data, $depth): ?int {
            for ($i = 0; $i < $count * $perItem && $at < \strlen($data); ++$i) {
                $at = self::skipMessagePack($data, $at, $depth + 1);
                if ($at === null) {
                    return null;
                }
            }
            return $at;
        };

        return match (true) {
            $byte <= 0x7f, $byte >= 0xe0 => $offset + 1,
            $byte <= 0x8f => $items($offset + 1, $byte & 0x0f, 2),
            $byte <= 0x9f => $items($offset + 1, $byte & 0x0f, 1),
            $byte <= 0xbf => $offset + 1 + ($byte & 0x1f),
            $byte === 0xc1 => null,
            $byte <= 0xc3 => $offset + 1,
            $byte === 0xc4, $byte === 0xd9 => $offset + 2 + $uint($offset + 1, 1),
            $byte === 0xc5, $byte === 0xda => $offset + 3 + $uint($offset + 1, 2),
            $byte === 0xc6, $byte === 0xdb => $offset + 5 + $uint($offset + 1, 4),
            $byte === 0xc7 => $offset + 3 + $uint($offset + 1, 1),
            $byte === 0xc8 => $offset + 4 + $uint($offset + 1, 2),
            $byte === 0xc9 => $offset + 6 + $uint($offset + 1, 4),
            $byte === 0xca, $byte === 0xce, $byte === 0xd2 => $offset + 5,
            $byte === 0xcb, $byte === 0xcf, $byte === 0xd3 => $offset + 9,
            $byte === 0xcc, $byte === 0xd0 => $offset + 2,
            $byte === 0xcd, $byte === 0xd1 => $offset + 3,
            $byte === 0xd4 => $offset + 3,
            $byte === 0xd5 => $offset + 4,
            $byte === 0xd6 => $offset + 6,
            $byte === 0xd7 => $offset + 10,
            $byte === 0xd8 => $offset + 18,
            $byte === 0xdc => $items($offset + 3, $uint($offset + 1, 2), 1),
            $byte === 0xdd => $items($offset + 5, $uint($offset + 1, 4), 1),
            $byte === 0xde => $items($offset + 3, $uint($offset + 1, 2), 2),
            default => $items($offset + 5, $uint($offset + 1, 4), 2),
        };
    }

    /**
     * The whole head must read as fields of a message: at least two, of the wire types proto3 uses.
     * A head of {@see HEAD} bytes may cut the last field; a shorter one is all the data.
     */
    private static function protobuf(string $head): ?self
    {
        $offset = 0;
        $length = \strlen($head);
        $cut = $length >= self::HEAD;
        $fields = 0;
        while ($offset < $length) {
            $tag = self::varint($head, $offset);
            $value = match ($tag === null ? -1 : $tag & 0x07) {
                0 => self::varint($head, $offset) === null ? null : 0,
                1 => 8,
                2 => self::varint($head, $offset),
                5 => 4,
                default => null,
            };
            if ($tag === null || $tag >> 3 === 0 || $value === null) {
                return $cut && $offset >= $length && $fields >= 2 ? self::protobufFormat() : null;
            }

            $offset += $value;
            ++$fields;
        }

        return $fields >= 2 && ($offset === $length || $cut) ? self::protobufFormat() : null;
    }

    private static function protobufFormat(): self
    {
        return new self(
            'protobuf',
            'Protocol Buffers message, probably',
            'Decode it with the .proto of the sender, or `protoc --decode_raw`.',
            'pb',
        );
    }

    /**
     * @param int $offset Moves past the varint.
     *
     * @return int|null Null when the data ends inside the varint or it is longer than 5 bytes.
     */
    private static function varint(string $data, int &$offset): ?int
    {
        $value = 0;
        for ($shift = 0; $shift < 35; $shift += 7) {
            if ($offset >= \strlen($data)) {
                return null;
            }
            $byte = \ord($data[$offset++]);
            $value |= ($byte & 0x7f) << $shift;
            if ($byte < 0x80) {
                return $value;
            }
        }

        return null;
    }

    /**
     * The host name of the `server_name` extension of a ClientHello in the first TLS record.
     */
    private static function serverName(string $hello): ?string
    {
        $length = \strlen($hello);
        $u16 = static fn(int $at): int => $at + 2 <= $length ? \ord($hello[$at]) << 8 | \ord($hello[$at + 1]) : -1;

        // Record header 5, handshake header 4, version 2, random 32
        $at = 43;
        $at += 1 + \ord($hello[$at] ?? "\xff");
        $at += 2 + $u16($at);
        $at += 1 + \ord($hello[$at] ?? "\xff");
        $end = $at + 2 + $u16($at);
        $at += 2;
        while ($at + 4 <= \min($end, $length)) {
            $type = $u16($at);
            $size = $u16($at + 2);
            $at += 4;
            if ($type === 0) {
                // List length 2, name type 1, name length 2
                $name = \substr($hello, $at + 5, \max(0, $u16($at + 3)));
                return \preg_match('/^[A-Za-z0-9.-]{1,253}$/', $name) === 1 ? $name : null;
            }
            $at += $size;
        }

        return null;
    }
}
