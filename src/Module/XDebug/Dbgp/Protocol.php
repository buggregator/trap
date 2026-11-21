<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\XDebug\Dbgp;

/**
 * The DBGp wire format: the engine sends `<length>\0<xml>\0`, the IDE sends `command -i <tid> args [-- base64]\0`.
 *
 * @internal
 */
final class Protocol
{
    /** Engine packets above this are a protocol error: a source file or a large array stays far below */
    public const MAX_PACKET = 32 << 20;

    /** IDE command lines above this are a protocol error */
    public const MAX_COMMAND = 1 << 20;

    public static function encodePacket(string $body): string
    {
        return \strlen($body) . "\0" . $body . "\0";
    }

    /**
     * A command line for the engine. No trailing space: Xdebug answers `parse error in command` to one.
     */
    public static function command(string $name, int $tid, string $args = '', ?string $data = null): string
    {
        $line = "$name -i $tid";
        $args === '' or $line .= ' ' . $args;
        $data === null or $line .= ' -- ' . \base64_encode($data);

        return $line;
    }

    /**
     * Quotes a command argument when it has a space, a quote, a backslash or a NUL.
     */
    public static function quote(string $value): string
    {
        if ($value !== '' && \strpbrk($value, " \"\\\0") === false) {
            return $value;
        }

        return '"' . \str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }

    /**
     * Finds the name and the value of `-i` in an IDE command line. The base64 data after `--` is not read.
     *
     * @return array{string, int, int}|null The command name and the byte span of the transaction ID;
     *         null when the line has no `-i`.
     */
    public static function commandTid(string $line): ?array
    {
        $tokens = [];
        $length = \strlen($line);
        $i = 0;
        while ($i < $length) {
            while ($i < $length && $line[$i] === ' ') {
                ++$i;
            }
            if ($i >= $length) {
                break;
            }
            $start = $i;
            if ($line[$i] === '"') {
                ++$i;
                while ($i < $length && $line[$i] !== '"') {
                    $line[$i] === '\\' and ++$i;
                    ++$i;
                }
                $i < $length and ++$i;
            } else {
                while ($i < $length && $line[$i] !== ' ') {
                    ++$i;
                }
            }
            if (\substr($line, $start, $i - $start) === '--') {
                break;
            }
            $tokens[] = [$start, $i];
        }

        for ($k = 1; $k + 1 < \count($tokens); ++$k) {
            if (\substr($line, $tokens[$k][0], $tokens[$k][1] - $tokens[$k][0]) === '-i') {
                return [\substr($line, 0, $tokens[0][1]), $tokens[$k + 1][0], $tokens[$k + 1][1]];
            }
        }

        return null;
    }

    /**
     * Reads the root element of a packet without parsing the XML: the relay looks at every packet.
     *
     * @return array{name: string, tid: string, status: string, command: string}
     */
    public static function root(string $body): array
    {
        $root = ['name' => '', 'tid' => '', 'status' => '', 'command' => ''];
        if (\preg_match('/<([a-z_]++)(\s[^>]*+)?>/i', (string) \preg_replace('/<\?xml[^>]*+\?>/', '', $body, 1), $m) !== 1) {
            return $root;
        }
        $root['name'] = $m[1];
        $attributes = $m[2] ?? '';
        foreach (['tid' => 'transaction_id', 'status' => 'status', 'command' => 'command'] as $key => $attribute) {
            \preg_match('/\s' . $attribute . '=(?:"([^"]*)"|\'([^\']*)\')/', $attributes, $a) === 1
                and $root[$key] = ($a[1] ?? '') !== '' ? $a[1] : ($a[2] ?? '');
        }

        return $root;
    }

    /**
     * Puts another transaction ID into the root element: the relay gives the IDE back its own.
     */
    public static function withTid(string $body, string $tid): string
    {
        return (string) \preg_replace(
            '/(<(?:response|notify|stream)\b[^>]*?\stransaction_id=)(?:"[^"]*"|\'[^\']*\')/',
            '${1}"' . \str_replace('$', '\$', \htmlspecialchars($tid, \ENT_QUOTES | \ENT_XML1)) . '"',
            $body,
            1,
        );
    }
}
