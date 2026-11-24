<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Telemetry\Insight\Sql;

/**
 * The shape of an SQL query: the same text for two queries that differ only in their values.
 *
 * One pass over the tokens, not regular expressions over the text, so a `?`, a number or a quote inside a string
 * literal or a comment stays in it:
 * - string literals (`'…'`, `E'…'`, `N'…'`, `X'…'`, `B'…'`, `$$…$$`, `$tag$…$tag$`), numbers (a leading sign too
 *   where an operand is due), `TRUE`, `FALSE` and the placeholders `?`, `?1`, `$1`, `:name` and `:1` become `?`;
 *   a `::type` cast stays;
 * - an `IN (…)` list, the rows of `VALUES (…), (…)` and an `ARRAY[…]` made of values, `NULL` and `DEFAULT` become
 *   one `(?)` or `[?]`;
 * - comments go, `"…"` and `` `…` `` identifiers stay as written, and so do the digits of a name, as in `table2`;
 * - SQL keywords are upper-cased, names keep their case;
 * - tokens are joined by one space, without one inside brackets, before a comma, around `.` and `::`,
 *   and between a name and its `(`.
 *
 * A backslash escapes the next character of a literal in the databases of {@see self::BACKSLASH_SYSTEMS}, and in
 * an `E'…'` literal anywhere; elsewhere only a doubled quote escapes one. For an unknown database a backslash
 * escapes, unless a literal of the query then never ends (one cut off with a long query does not count): then none
 * does in that query, and `'C:\' AND b = 'y'` has two literals. The query is read once or twice, never once per
 * literal.
 *
 * Only the first {@see self::MAX_BYTES} of a query are read, the work of the loop it blocks being bounded so:
 * a longer query gets the shape of that part and ` …`, and a list cut off at the end still collapses. Two queries
 * that differ only past that part have one shape.
 *
 * @internal
 */
final class QueryShape
{
    public const MAX_BYTES = 32 * 1024;
    private const KEYWORDS = [
        'ALL' => true, 'AND' => true, 'ANY' => true, 'ARRAY' => true, 'AS' => true, 'ASC' => true,
        'BETWEEN' => true, 'BY' => true, 'CASE' => true, 'CONFLICT' => true, 'CROSS' => true, 'DEFAULT' => true,
        'DELETE' => true, 'DESC' => true, 'DISTINCT' => true, 'DO' => true, 'DUPLICATE' => true, 'ELSE' => true,
        'END' => true, 'ESCAPE' => true, 'EXCEPT' => true, 'EXISTS' => true, 'FETCH' => true, 'FOR' => true,
        'FROM' => true, 'FULL' => true, 'GROUP' => true, 'HAVING' => true, 'ILIKE' => true, 'IN' => true,
        'INNER' => true, 'INSERT' => true, 'INTERSECT' => true, 'INTO' => true, 'IS' => true, 'JOIN' => true,
        'LEFT' => true, 'LIKE' => true, 'LIMIT' => true, 'NATURAL' => true, 'NOT' => true, 'NULL' => true,
        'NULLS' => true, 'OFFSET' => true, 'ON' => true, 'OR' => true, 'ORDER' => true, 'OUTER' => true,
        'OVER' => true, 'PARTITION' => true, 'RECURSIVE' => true, 'RETURNING' => true, 'RIGHT' => true,
        'SELECT' => true, 'SET' => true, 'SOME' => true, 'THEN' => true, 'UNION' => true, 'UPDATE' => true,
        'USING' => true, 'VALUES' => true, 'WHEN' => true, 'WHERE' => true, 'WITH' => true,
    ];

    /** Operators of more than one character; the longest one wins */
    private const OPERATORS = [
        '->>' => true, '#>>' => true, '<=>' => true, '::' => true, ':=' => true, '->' => true, '#>' => true,
        '<>' => true, '<=' => true, '>=' => true, '!=' => true, '||' => true, '&&' => true, '@>' => true, '<@' => true,
    ];

    /** The `db.system` and `db.system.name` values of databases whose literals take backslash escapes */
    private const BACKSLASH_SYSTEMS = [
        'mysql', 'mariadb', 'clickhouse', 'hive', 'spanner', 'gcp.spanner',
    ];

    /** Lists nested deeper are left as they are */
    private const MAX_DEPTH = 32;

    private const VALUE = ['value', '?'];

    private readonly string $sql;
    private readonly int $length;
    private readonly bool $truncated;

    /** Whether a backslash escapes in a literal of the current read */
    private bool $escapes;

    /** Whether the current read met a literal that never ends */
    private bool $unclosed = false;

    /**
     * @param bool|null $backslash Whether a backslash escapes in a literal; null when the database is unknown.
     */
    private function __construct(
        string $sql,
        private readonly ?bool $backslash,
    ) {
        $this->escapes = $backslash ?? true;
        $this->truncated = \strlen($sql) > self::MAX_BYTES;
        $this->sql = $this->truncated ? \substr($sql, 0, self::MAX_BYTES) : $sql;
        $this->length = \strlen($this->sql);
    }

    /**
     * @param string|null $system The `db.system` or `db.system.name` of the span, such as `mysql`; null when unknown.
     * @return string Empty for a text without tokens.
     */
    public static function of(string $sql, ?string $system = null): string
    {
        $system = $system === null || $system === '' ? null : \strtolower($system);
        $shape = new self($sql, $system === null ? null : \in_array($system, self::BACKSLASH_SYSTEMS, true));

        return $shape->shape();
    }

    /**
     * @param array{string, string}|null $previous
     */
    private static function expectsOperand(?array $previous): bool
    {
        if ($previous === null) {
            return true;
        }

        return match ($previous[0]) {
            'op' => true,
            'keyword' => $previous[1] !== 'NULL' && $previous[1] !== 'END',
            'punct' => \in_array($previous[1], ['(', ',', '['], true),
            default => false,
        };
    }

    /**
     * @param array{string, string} $previous
     * @param array{string, string} $token
     */
    private static function spaced(array $previous, array $token): bool
    {
        if ($token[0] === 'punct' && \in_array($token[1], [',', ')', '.', ';', ']'], true)) {
            return false;
        }

        if ($previous === ['punct', '('] || $previous === ['punct', '.'] || $previous === ['punct', '[']) {
            return false;
        }

        if ($token === ['op', '::'] || $previous === ['op', '::']) {
            return false;
        }

        if ($token === ['punct', '['] && $previous === ['keyword', 'ARRAY']) {
            return false;
        }

        if ($token === ['punct', '('] || $token === ['punct', '[']) {
            // A call or an index sticks to its name; a list after a keyword or an operator does not
            return !\in_array($previous[0], ['word', 'value'], true)
                && $previous !== ['punct', ')']
                && $previous !== ['punct', ']'];
        }

        return true;
    }

    private function shape(): string
    {
        $tokens = $this->tokenize();
        if ($this->unclosed && $this->backslash === null) {
            $this->escapes = false;
            $tokens = $this->tokenize();
        }

        $tokens = $this->collapseLists($tokens);
        while ($tokens !== [] && \end($tokens) === ['punct', ';']) {
            \array_pop($tokens);
        }

        $shape = '';
        $previous = null;
        foreach ($tokens as $token) {
            $previous === null || !self::spaced($previous, $token) or $shape .= ' ';
            $shape .= $token[1];
            $previous = $token;
        }

        return $this->truncated && $shape !== '' ? "$shape …" : $shape;
    }

    /**
     * @return list<array{'value'|'word'|'keyword'|'punct'|'op', string}>
     */
    private function tokenize(): array
    {
        $sql = $this->sql;
        $length = $this->length;
        $tokens = [];
        $i = 0;
        while ($i < $length) {
            $char = $sql[$i];
            $next = $sql[$i + 1] ?? '';

            if (\ctype_space($char)) {
                ++$i;
            } elseif ($char === '-' && $next === '-') {
                $end = \strpos($sql, "\n", $i);
                $i = $end === false ? $length : $end + 1;
            } elseif ($char === '/' && $next === '*') {
                $end = \strpos($sql, '*/', $i + 2);
                $i = $end === false ? $length : $end + 2;
            } elseif ($char === "'") {
                $i = $this->stringEnd($i, $this->escapes);
                $tokens[] = self::VALUE;
            } elseif ($char === '"' || $char === '`') {
                $end = $this->identifierEnd($i);
                $tokens[] = ['word', \substr($sql, $i, $end - $i)];
                $i = $end;
            } elseif ($char === '$' && \preg_match('/\G\$(?:\d+|([A-Za-z_]\w*)?\$)/', $sql, $match, 0, $i) === 1) {
                $i += \strlen($match[0]);
                if (\str_ends_with($match[0], '$')) {
                    // A dollar-quoted string ends at the same tag
                    $end = \strpos($sql, $match[0], $i);
                    $i = $end === false ? $length : $end + \strlen($match[0]);
                }
                $tokens[] = self::VALUE;
            } elseif ($char === '?') {
                $i += \strspn($sql, '0123456789', $i + 1) + 1;
                $tokens[] = self::VALUE;
            } elseif ($char === ':' && $next === ':') {
                $tokens[] = ['op', '::'];
                $i += 2;
            } elseif ($char === ':' && \preg_match('/\G:(?:[A-Za-z_]\w*|\d+)/', $sql, $match, 0, $i) === 1) {
                $tokens[] = self::VALUE;
                $i += \strlen($match[0]);
            } elseif (
                (\ctype_digit($char) || $char === '.' || $char === '-' || $char === '+')
                && ($number = $this->numberLength($i, $tokens === [] ? null : $tokens[\array_key_last($tokens)])) > 0
            ) {
                $tokens[] = self::VALUE;
                $i += $number;
            } elseif (\preg_match('/\G[\w\x80-\xff][\w$\x80-\xff]*/', $sql, $match, 0, $i) === 1) {
                $i += \strlen($match[0]);
                $upper = \strtoupper($match[0]);
                if (\in_array($upper, ['E', 'N', 'X', 'B'], true) && ($sql[$i] ?? '') === "'") {
                    $i = $this->stringEnd($i, $upper === 'E' || $this->escapes);
                    $tokens[] = self::VALUE;
                } elseif ($upper === 'TRUE' || $upper === 'FALSE') {
                    $tokens[] = self::VALUE;
                } else {
                    $tokens[] = isset(self::KEYWORDS[$upper]) ? ['keyword', $upper] : ['word', $match[0]];
                }
            } elseif ($char === '@' && \preg_match('/\G@@?[A-Za-z_][\w$.]*/', $sql, $match, 0, $i) === 1) {
                $tokens[] = ['word', $match[0]];
                $i += \strlen($match[0]);
            } elseif (\in_array($char, ['(', ')', ',', ';', '.', '[', ']'], true)) {
                $tokens[] = ['punct', $char];
                ++$i;
            } else {
                $operator = \substr($sql, $i, 3);
                isset(self::OPERATORS[$operator]) or $operator = \substr($operator, 0, 2);
                isset(self::OPERATORS[$operator]) or $operator = $char;
                $tokens[] = ['op', $operator];
                $i += \strlen($operator);
            }
        }

        return $tokens;
    }

    /**
     * @param int $start At the opening quote.
     * @return int After the closing quote; the end of the text for an unclosed literal.
     */
    private function stringEnd(int $start, bool $backslash): int
    {
        $end = $this->literalEnd($start, $backslash);
        // A literal cut off with the text may well end past the cut
        $end === null && !$this->truncated and $this->unclosed = true;

        return $end ?? $this->length;
    }

    /**
     * @return int|null After the closing quote; null for an unclosed literal.
     */
    private function literalEnd(int $start, bool $backslash): ?int
    {
        $sql = $this->sql;
        for ($i = $start + 1; $i < $this->length; ++$i) {
            if ($backslash && $sql[$i] === '\\') {
                ++$i;
            } elseif ($sql[$i] === "'") {
                if (($sql[$i + 1] ?? '') !== "'") {
                    return $i + 1;
                }
                ++$i;
            }
        }

        return null;
    }

    /**
     * @param int $start At the opening `"` or `` ` ``, which a doubled one escapes.
     */
    private function identifierEnd(int $start): int
    {
        $sql = $this->sql;
        $quote = $sql[$start];
        for ($i = $start + 1; $i < $this->length; ++$i) {
            if ($sql[$i] === $quote) {
                if (($sql[$i + 1] ?? '') !== $quote) {
                    return $i + 1;
                }
                ++$i;
            }
        }

        return $this->length;
    }

    /**
     * @param array{string, string}|null $previous The token before: a sign is part of a number only where
     *        an operand is due, so `a - 1` keeps its minus and `a = - 1` does not.
     * @return int 0 when no number starts here, or one runs into a name, as `2fa`.
     */
    private function numberLength(int $start, ?array $previous): int
    {
        $sql = $this->sql;
        $sign = 0;
        if (($sql[$start] === '-' || $sql[$start] === '+') && self::expectsOperand($previous)) {
            $sign = 1 + \strspn($sql, " \t\r\n", $start + 1);
        }

        $pattern = '/\G(?:0[xX][\da-fA-F]+|(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?)(?![\w$\x80-\xff])/';
        if (\preg_match($pattern, $sql, $match, 0, $start + $sign) !== 1) {
            return 0;
        }

        return $sign + \strlen($match[0]);
    }

    /**
     * @param list<array{'value'|'word'|'keyword'|'punct'|'op', string}> $tokens
     * @return list<array{'value'|'word'|'keyword'|'punct'|'op', string}>
     */
    private function collapseLists(array $tokens): array
    {
        $result = [];
        $count = \count($tokens);
        for ($i = 0; $i < $count; ++$i) {
            $token = $tokens[$i];
            $result[] = $token;
            if ($token === ['keyword', 'IN'] || $token === ['keyword', 'VALUES']) {
                [$open, $close] = ['(', ')'];
            } elseif ($token === ['keyword', 'ARRAY']) {
                [$open, $close] = ['[', ']'];
            } else {
                continue;
            }

            $end = $this->listEnd($tokens, $i + 1, $open, $close);
            while ($end !== null && $end < $count && $token[1] === 'VALUES' && $tokens[$end] === ['punct', ',']) {
                $row = $this->listEnd($tokens, $end + 1, $open, $close);
                if ($row === null) {
                    break;
                }
                $end = $row;
            }

            if ($end !== null) {
                \array_push($result, ['punct', $open], self::VALUE, ['punct', $close]);
                $i = $end - 1;
            }
        }

        return $result;
    }

    /**
     * @param list<array{string, string}> $tokens
     * @param '('|'[' $open
     * @param ')'|']' $close
     * @return int|null After the closing bracket of a list of values, `NULL`, `DEFAULT` and such lists, starting
     *         at `$start`, or the end of the tokens of a cut query; null when there is none.
     */
    private function listEnd(array $tokens, int $start, string $open, string $close, int $depth = 0): ?int
    {
        $count = \count($tokens);
        if ($this->truncated && $start >= $count) {
            return $count;
        }

        if ($depth >= self::MAX_DEPTH || ($tokens[$start] ?? null) !== ['punct', $open]) {
            return null;
        }

        $i = $start + 1;
        while (true) {
            $token = $tokens[$i] ?? null;
            if ($token === self::VALUE || $token === ['keyword', 'NULL'] || $token === ['keyword', 'DEFAULT']) {
                ++$i;
            } elseif ($token === ['punct', $open]) {
                $i = $this->listEnd($tokens, $i, $open, $close, $depth + 1);
                if ($i === null) {
                    return null;
                }
            } else {
                return $token === null && $this->truncated ? $count : null;
            }

            $token = $tokens[$i] ?? null;
            if ($token === ['punct', $close]) {
                return $i + 1;
            }
            if ($token !== ['punct', ',']) {
                return $token === null && $this->truncated ? $count : null;
            }
            ++$i;
        }
    }
}
