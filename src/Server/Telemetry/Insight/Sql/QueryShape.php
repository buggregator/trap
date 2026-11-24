<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Telemetry\Insight\Sql;

/**
 * The shape of an SQL query: the same text for two queries that differ only in their values.
 *
 * One pass over the tokens, not regular expressions over the text, so a `?`, a number or a quote inside a string
 * literal or a comment stays in it:
 * - string literals (`'…'` with `''` and `\'` escapes, `E'…'`, `N'…'`, `X'…'`, `B'…'`, `$$…$$`, `$tag$…$tag$`),
 *   numbers (a leading sign too where an operand is due), `TRUE`, `FALSE` and the placeholders `?`, `?1`, `$1`,
 *   `:name` and `:1` become `?`; a `::type` cast stays;
 * - an `IN (…)` list and the rows of `VALUES (…), (…)` made of values, `NULL` and `DEFAULT` become one `(?)`;
 * - comments go, `"…"` and `` `…` `` identifiers stay as written, and so do the digits of a name, as in `table2`;
 * - SQL keywords are upper-cased, names keep their case;
 * - tokens are joined by one space, without one inside brackets, before a comma, around `.` and `::`,
 *   and between a name and its `(`.
 *
 * @internal
 */
final class QueryShape
{
    private const KEYWORDS = [
        'ALL', 'AND', 'ANY', 'AS', 'ASC', 'BETWEEN', 'BY', 'CASE', 'CONFLICT', 'CROSS', 'DEFAULT', 'DELETE', 'DESC',
        'DISTINCT', 'DO', 'DUPLICATE', 'ELSE', 'END', 'ESCAPE', 'EXCEPT', 'EXISTS', 'FETCH', 'FOR', 'FROM', 'FULL',
        'GROUP', 'HAVING', 'ILIKE', 'IN', 'INNER', 'INSERT', 'INTERSECT', 'INTO', 'IS', 'JOIN', 'LEFT', 'LIKE',
        'LIMIT', 'NATURAL', 'NOT', 'NULL', 'NULLS', 'OFFSET', 'ON', 'OR', 'ORDER', 'OUTER', 'OVER', 'PARTITION',
        'RECURSIVE', 'RETURNING', 'RIGHT', 'SELECT', 'SET', 'SOME', 'THEN', 'UNION', 'UPDATE', 'USING', 'VALUES',
        'WHEN', 'WHERE', 'WITH',
    ];

    /** Longest first: the first match wins */
    private const OPERATORS = ['->>', '#>>', '<=>', '::', ':=', '->', '#>', '<>', '<=', '>=', '!=', '||', '&&', '@>', '<@'];

    private const VALUE = ['value', '?'];

    /**
     * @return string Empty for a text without tokens.
     */
    public static function of(string $sql): string
    {
        $tokens = self::collapseLists(self::tokenize($sql));
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

        return $shape;
    }

    /**
     * @return list<array{'value'|'word'|'keyword'|'punct'|'op', string}>
     */
    private static function tokenize(string $sql): array
    {
        $tokens = [];
        $length = \strlen($sql);
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
                $i = self::stringEnd($sql, $i);
                $tokens[] = self::VALUE;
            } elseif ($char === '"' || $char === '`') {
                $end = self::identifierEnd($sql, $i);
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
            } elseif (($number = self::numberLength($sql, $i, $tokens)) > 0) {
                $tokens[] = self::VALUE;
                $i += $number;
            } elseif (\preg_match('/\G[\w\x80-\xff][\w$\x80-\xff]*/', $sql, $match, 0, $i) === 1) {
                $i += \strlen($match[0]);
                $upper = \strtoupper($match[0]);
                if (\in_array($upper, ['E', 'N', 'X', 'B'], true) && ($sql[$i] ?? '') === "'") {
                    $i = self::stringEnd($sql, $i);
                    $tokens[] = self::VALUE;
                } elseif ($upper === 'TRUE' || $upper === 'FALSE') {
                    $tokens[] = self::VALUE;
                } else {
                    $tokens[] = \in_array($upper, self::KEYWORDS, true) ? ['keyword', $upper] : ['word', $match[0]];
                }
            } elseif ($char === '@' && \preg_match('/\G@@?[A-Za-z_][\w$.]*/', $sql, $match, 0, $i) === 1) {
                $tokens[] = ['word', $match[0]];
                $i += \strlen($match[0]);
            } elseif (\in_array($char, ['(', ')', ',', ';', '.', '[', ']'], true)) {
                $tokens[] = ['punct', $char];
                ++$i;
            } else {
                $operator = $char;
                foreach (self::OPERATORS as $candidate) {
                    if (\substr_compare($sql, $candidate, $i, \strlen($candidate)) === 0) {
                        $operator = $candidate;
                        break;
                    }
                }
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
    private static function stringEnd(string $sql, int $start): int
    {
        $length = \strlen($sql);
        for ($i = $start + 1; $i < $length; ++$i) {
            if ($sql[$i] === '\\') {
                ++$i;
            } elseif ($sql[$i] === "'") {
                if (($sql[$i + 1] ?? '') !== "'") {
                    return $i + 1;
                }
                ++$i;
            }
        }

        return $length;
    }

    /**
     * @param int $start At the opening `"` or `` ` ``, which a doubled one escapes.
     */
    private static function identifierEnd(string $sql, int $start): int
    {
        $quote = $sql[$start];
        $length = \strlen($sql);
        for ($i = $start + 1; $i < $length; ++$i) {
            if ($sql[$i] === $quote) {
                if (($sql[$i + 1] ?? '') !== $quote) {
                    return $i + 1;
                }
                ++$i;
            }
        }

        return $length;
    }

    /**
     * @param list<array{string, string}> $tokens The tokens before: a sign is part of a number only where
     *        an operand is due, so `a - 1` keeps its minus and `a = -1` does not.
     * @return int<0, max> 0 when no number starts here, or one runs into a name, as `2fa`.
     */
    private static function numberLength(string $sql, int $start, array $tokens): int
    {
        $sign = ($sql[$start] === '-' || $sql[$start] === '+') && self::expectsOperand(\end($tokens) ?: null) ? 1 : 0;
        $pattern = '/\G(?:0[xX][\da-fA-F]+|(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?)(?![\w$\x80-\xff])/';
        if (\preg_match($pattern, $sql, $match, 0, $start + $sign) !== 1) {
            return 0;
        }

        return $sign + \strlen($match[0]);
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
            'keyword' => $previous[1] !== 'NULL',
            'punct' => \in_array($previous[1], ['(', ',', '['], true),
            default => false,
        };
    }

    /**
     * @param list<array{'value'|'word'|'keyword'|'punct'|'op', string}> $tokens
     * @return list<array{'value'|'word'|'keyword'|'punct'|'op', string}>
     */
    private static function collapseLists(array $tokens): array
    {
        $result = [];
        $count = \count($tokens);
        for ($i = 0; $i < $count; ++$i) {
            $token = $tokens[$i];
            $result[] = $token;
            if ($token !== ['keyword', 'IN'] && $token !== ['keyword', 'VALUES']) {
                continue;
            }

            $end = self::tupleEnd($tokens, $i + 1);
            while ($end !== null && $token[1] === 'VALUES' && ($tokens[$end] ?? null) === ['punct', ',']) {
                $row = self::tupleEnd($tokens, $end + 1);
                if ($row === null) {
                    break;
                }
                $end = $row;
            }

            if ($end !== null) {
                \array_push($result, ['punct', '('], self::VALUE, ['punct', ')']);
                $i = $end - 1;
            }
        }

        return $result;
    }

    /**
     * @param list<array{string, string}> $tokens
     * @return int|null After the `)` of a bracketed list of values, `NULL`, `DEFAULT` and such lists, starting
     *         at `$start`; null when there is none.
     */
    private static function tupleEnd(array $tokens, int $start): ?int
    {
        if (($tokens[$start] ?? null) !== ['punct', '(']) {
            return null;
        }

        $i = $start + 1;
        while (true) {
            $token = $tokens[$i] ?? null;
            if ($token === self::VALUE || $token === ['keyword', 'NULL'] || $token === ['keyword', 'DEFAULT']) {
                ++$i;
            } elseif ($token === ['punct', '(']) {
                $i = self::tupleEnd($tokens, $i);
                if ($i === null) {
                    return null;
                }
            } else {
                return null;
            }

            $token = $tokens[$i] ?? null;
            if ($token === ['punct', ')']) {
                return $i + 1;
            }
            if ($token !== ['punct', ',']) {
                return null;
            }
            ++$i;
        }
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

        if ($token === ['punct', '('] || $token === ['punct', '[']) {
            // A call or an index sticks to its name; a list after a keyword or an operator does not
            return !\in_array($previous[0], ['word', 'value'], true)
                && $previous !== ['punct', ')']
                && $previous !== ['punct', ']'];
        }

        return true;
    }
}
