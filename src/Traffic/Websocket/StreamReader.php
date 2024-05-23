<?php

declare(strict_types=1);

namespace Buggregator\Trap\Traffic\Websocket;

/**
 * Read Websocket Frames from the Stream.
 *
 * @internal
 */
final class StreamReader
{
    /**
     * @param iterable<array-key, string> $chunks
     *
     * @return \Generator<array-key, Frame, mixed, void> Returns the remaining content of the last chunk
     */
    public static function readFrames(iterable $chunks): \Generator
    {
        $parser = self::frameParser();
        $reader = (static fn() => yield from $chunks)();
        $buffer = '';
        $isFirst = true;
        /** @var \Closure(int<1, max>): ?non-empty-string $read */
        $read = static function (int $len) use (&$buffer, $reader, &$isFirst): ?string {
            /** @var string $buffer */
            while (\strlen($buffer) < $len) {
                if (!$reader->valid()) {
                    return null;
                }
                $isFirst or $reader->next();
                $isFirst = false;
                $buffer .= $reader->current();
            }

            $result = \substr($buffer, 0, $len);
            $buffer = \substr($buffer, $len);

            return $result;
        };

        while (true) {
            $y = $parser->current();
            if (\is_int($y)) {
                // Parser requests more bytes
                $r = $read($y);
                if ($r === null) {
                    break;
                }
                $parser->send($r);
                continue;
            }

            // Parser yields a frame
            yield $y;
            $parser->next();
        }
    }

    /**
     * @psalm-suppress InvalidReturnType
     * @return \Generator<int, Frame|int<1, max>, non-empty-string, null>
     */
    private static function frameParser(): \Generator
    {
        while (true) {
            // Read first byte
            $c = \ord(yield 1);
            $fin = (bool) ($c & 128);
            $opcode = Opcode::from($c & 0x0f);
            $rsv1 = (bool) ($c & 64);

            // Read second byte
            $c = \ord(yield 1);
            $len = $c & 127;
            $isMask = (bool) ($c & 128);

            // Parse length
            if ($len === 126) {
                /** @var int $len */
                $len = \unpack('n', yield 2)[1];
            } elseif ($len === 127) {
                /** @var int $len */
                $len = \unpack('J', yield 8)[1];
            }

            // Read mask
            $mask = $isMask ? (yield 4) : null;

            $body = yield $len;

            // Apply mask
            if ($isMask) {
                for ($i = 0; $i < $len; ++$i) {
                    /** @psalm-suppress PossiblyNullArrayAccess, PossiblyNullArgument */
                    $body[$i] = \chr(\ord($body[$i]) ^ \ord($mask[$i % 4]));
                }
            }

            yield new Frame(
                $body,
                $opcode,
                $fin,
                $rsv1,
            );
        }
    }
}
