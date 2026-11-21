<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\XDebugTrace\Trace;

/**
 * One data line of a trace in the computer-readable format (`xdebug.trace_format=1`).
 *
 * @internal
 */
final class Record
{
    public const ENTRY = 'E';
    public const EXIT = 'X';
    public const RETURN = 'R';
    public const ASSIGN = 'A';

    /**
     * @param self::ENTRY|self::EXIT|self::RETURN|self::ASSIGN $kind
     * @param int $nr Function number; 0 for assignments.
     * @param float $time Seconds since the trace started (entry, exit).
     * @param int $memory Bytes (entry, exit).
     * @param list<string> $args Entry: the arguments, with `xdebug.collect_params`.
     * @param string $value The return value, or the assignment text as `$a = 1`.
     */
    public function __construct(
        public readonly string $kind,
        public readonly int $level,
        public readonly int $nr = 0,
        public readonly float $time = 0.0,
        public readonly int $memory = 0,
        public readonly string $func = '',
        public readonly bool $user = false,
        public readonly string $include = '',
        public readonly string $file = '',
        public readonly int $line = 0,
        public readonly array $args = [],
        public readonly string $value = '',
    ) {}

    /**
     * Fields are tab-separated:
     *
     *     level  fn#  0  time  memory  function  user(1)/internal(0)  include  file  line  [argc  args...]   entry
     *     level  fn#  1  time  memory                                                                        exit
     *     level  fn#  R           value                                                                      return
     *     level       A                    file  line  $var = value                                          assignment
     */
    public static function parse(string $line): ?self
    {
        $f = \explode("\t", $line);
        if (\count($f) < 3 || !\ctype_digit($f[0])) {
            return null;
        }

        $level = (int) $f[0];
        switch ($f[2]) {
            case '0':
                if (\count($f) < 10 || !\ctype_digit($f[1]) || !\is_numeric($f[3])) {
                    return null;
                }
                $args = [];
                if (\count($f) > 10) {
                    $args = \array_slice($f, 11);
                    $n = (int) $f[10];
                    $n >= 0 && $n < \count($args) and $args = \array_slice($args, 0, $n);
                }

                return new self(
                    kind: self::ENTRY,
                    level: $level,
                    nr: (int) $f[1],
                    time: (float) $f[3],
                    memory: (int) $f[4],
                    func: $f[5],
                    user: $f[6] === '1',
                    include: $f[7],
                    file: $f[8],
                    line: (int) $f[9],
                    args: $args,
                );
            case '1':
                if (\count($f) < 5 || !\ctype_digit($f[1]) || !\is_numeric($f[3])) {
                    return null;
                }

                return new self(self::EXIT, $level, (int) $f[1], (float) $f[3], (int) $f[4]);
            case 'R':
                if (!\ctype_digit($f[1])) {
                    return null;
                }

                return new self(
                    kind: self::RETURN,
                    level: $level,
                    nr: (int) $f[1],
                    value: \count($f) >= 6 ? \implode("\t", \array_slice($f, 5)) : '',
                );
            case 'A':
                // level, "", "A", 5 empty fields, file, line, "$var = value"
                return \count($f) >= 11
                    ? new self(
                        kind: self::ASSIGN,
                        level: $level,
                        file: $f[8],
                        line: (int) $f[9],
                        value: \implode("\t", \array_slice($f, 10)),
                    )
                    : new self(self::ASSIGN, $level);
            default:
                return null;
        }
    }
}
