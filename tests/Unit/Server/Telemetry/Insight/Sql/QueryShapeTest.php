<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Server\Telemetry\Insight\Sql;

use Buggregator\Trap\Server\Telemetry\Insight\Sql\QueryShape;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(QueryShape::class)]
final class QueryShapeTest
{
    public static function shapes(): iterable
    {
        yield 'numbers' => ['SELECT * FROM users WHERE id = 42 AND score > 1.5 AND big = 1e10', 'SELECT * FROM users WHERE id = ? AND score > ? AND big = ?'];
        yield 'hex number' => ['SELECT * FROM t WHERE flags = 0xFF', 'SELECT * FROM t WHERE flags = ?'];
        yield 'signed number where an operand is due' => ['SELECT * FROM t WHERE a = -5 AND b IN (-1, +2)', 'SELECT * FROM t WHERE a = ? AND b IN (?)'];
        yield 'binary minus stays' => ['UPDATE t SET stock = stock - 1, n = n-2', 'UPDATE t SET stock = stock - ?, n = n - ?'];
        yield 'digits of names stay' => ['SELECT col1, t2.x3 FROM table2 t2 JOIN s3_logs ON 1 = 1', 'SELECT col1, t2.x3 FROM table2 t2 JOIN s3_logs ON ? = ?'];
        yield 'a name that starts with a digit' => ['SELECT * FROM 2fa_codes WHERE id = 7', 'SELECT * FROM 2fa_codes WHERE id = ?'];
        yield 'string literal' => ["SELECT * FROM users WHERE email = 'a@b.c'", 'SELECT * FROM users WHERE email = ?'];
        yield 'doubled quote in a literal' => ["SELECT * FROM users WHERE name = 'O''Brien' AND id = 1", 'SELECT * FROM users WHERE name = ? AND id = ?'];
        yield 'backslash-escaped quote in a literal' => ["SELECT * FROM users WHERE name = 'O\\'Brien' AND id = 1", 'SELECT * FROM users WHERE name = ? AND id = ?'];
        yield 'placeholder and number inside a literal' => ["SELECT * FROM t WHERE note = 'is it 5? or :x' AND id = ?", 'SELECT * FROM t WHERE note = ? AND id = ?'];
        yield 'prefixed literals' => ["SELECT * FROM t WHERE a = E'x\\'y' AND b = N'z' AND c = X'0F' AND d = B'101'", 'SELECT * FROM t WHERE a = ? AND b = ? AND c = ? AND d = ?'];
        yield 'dollar-quoted literals' => ['SELECT * FROM t WHERE a = $$it\'s$$ AND b = $tag$x $$ y$tag$', 'SELECT * FROM t WHERE a = ? AND b = ?'];
        yield 'unclosed literal' => ["SELECT * FROM t WHERE a = 'oops", 'SELECT * FROM t WHERE a = ?'];
        yield 'numbered placeholders' => ['SELECT * FROM t WHERE a = $1 AND b = $12 AND c = ?3', 'SELECT * FROM t WHERE a = ? AND b = ? AND c = ?'];
        yield 'named placeholders' => ['SELECT * FROM t WHERE a = :id AND b = :user_id AND c = :1', 'SELECT * FROM t WHERE a = ? AND b = ? AND c = ?'];
        yield 'a cast is no placeholder' => ['SELECT id::text, $1::int FROM t', 'SELECT id::text, ?::int FROM t'];
        yield 'booleans' => ['SELECT * FROM t WHERE active = true AND deleted = FALSE', 'SELECT * FROM t WHERE active = ? AND deleted = ?'];
        yield 'NULL checks stay' => ['SELECT * FROM t WHERE a IS NULL AND b IS NOT NULL', 'SELECT * FROM t WHERE a IS NULL AND b IS NOT NULL'];
        yield 'IN list of any length' => ['SELECT * FROM t WHERE id IN (1, 2, 3)', 'SELECT * FROM t WHERE id IN (?)'];
        yield 'IN list of placeholders' => ['SELECT * FROM t WHERE id IN (?,?,?,?)', 'SELECT * FROM t WHERE id IN (?)'];
        yield 'NOT IN list with NULL' => ["SELECT * FROM t WHERE id NOT IN ('a', NULL)", 'SELECT * FROM t WHERE id NOT IN (?)'];
        yield 'IN list of tuples' => ['SELECT * FROM t WHERE (a, b) IN ((1, 2), (3, 4))', 'SELECT * FROM t WHERE (a, b) IN (?)'];
        yield 'IN subquery stays' => ['SELECT * FROM t WHERE id IN (SELECT user_id FROM orders)', 'SELECT * FROM t WHERE id IN (SELECT user_id FROM orders)'];
        yield 'IN list with an expression stays' => ['SELECT * FROM t WHERE d IN (?, NOW())', 'SELECT * FROM t WHERE d IN (?, NOW())'];
        yield 'VALUES rows' => ["INSERT INTO t (a, b) VALUES (1, 'x'), (2, NULL), (DEFAULT, ?)", 'INSERT INTO t(a, b) VALUES (?)'];
        yield 'VALUES row' => ['INSERT INTO t (a) VALUES (?) ON CONFLICT DO NOTHING', 'INSERT INTO t(a) VALUES (?) ON CONFLICT DO NOTHING'];
        yield 'block comment' => ["SELECT * FROM t /* traceparent='00-abc-01' */ WHERE id = 1", 'SELECT * FROM t WHERE id = ?'];
        yield 'line comment' => ["SELECT * FROM t -- by id 5\nWHERE id = 1", 'SELECT * FROM t WHERE id = ?'];
        yield 'quoted identifiers stay' => ['SELECT "Order"."id", `user``s`.x FROM "Order"', 'SELECT "Order"."id", `user``s`.x FROM "Order"'];
        yield 'keywords upper-cased, names not' => ['select Id from Users where Id = 1 order by Id desc limit 10', 'SELECT Id FROM Users WHERE Id = ? ORDER BY Id DESC LIMIT ?'];
        yield 'whitespace and spacing' => ["SELECT\n\tcount(*)  FROM t WHERE a=1 AND f( x ,y )>2;", 'SELECT count(*) FROM t WHERE a = ? AND f(x, y) > ?'];
        yield 'operators' => ["SELECT * FROM t WHERE a <> 1 AND b->>'k' = ? AND c != 2 AND d <= 3", 'SELECT * FROM t WHERE a <> ? AND b ->> ? = ? AND c != ? AND d <= ?'];
        yield 'variables stay' => ['SELECT @@version, @row := 1', 'SELECT @@version, @row := ?'];
        yield 'only a comment' => ['/* nothing */ -- here', ''];
        yield 'blank' => ["  \n ", ''];
        yield 'ARRAY of values' => ['SELECT * FROM t WHERE id = ANY(ARRAY[1, 2, 3]) AND tags && array[\'a\']', 'SELECT * FROM t WHERE id = ANY (ARRAY[?]) AND tags && ARRAY[?]'];
        yield 'nested ARRAY' => ['SELECT ARRAY[[1, 2], [3, NULL]]', 'SELECT ARRAY[?]'];
        yield 'ARRAY with a name stays' => ['SELECT ARRAY[a, 1]', 'SELECT ARRAY[a, ?]'];
        yield 'index stays' => ['SELECT tags[1] FROM t', 'SELECT tags[?] FROM t'];
        yield 'minus after END is binary' => ['SELECT CASE WHEN a THEN 1 END - 1, (2) - 3, x[1] - 4', 'SELECT CASE WHEN a THEN ? END - ?, (?) - ?, x[?] - ?'];
        yield 'sign apart from its number' => ["SELECT * FROM t WHERE x = -  1 AND y = +\t2", 'SELECT * FROM t WHERE x = ? AND y = ?'];
        yield 'nesting within the bound collapses' => ['SELECT * FROM t WHERE (a, (b, c)) IN ((1, (2, 3)))', 'SELECT * FROM t WHERE (a, (b, c)) IN (?)'];
        yield 'nesting past the bound stays' => ['SELECT 1 IN ' . \str_repeat('(', 40) . '1' . \str_repeat(')', 40), 'SELECT ? IN ' . \str_repeat('(', 40) . '?' . \str_repeat(')', 40)];
    }

    public static function dialects(): iterable
    {
        $path = "SELECT * FROM t WHERE p = 'C:\\' AND id = 5";
        $quote = "SELECT * FROM t WHERE n = 'it\\'s' AND id = 5";
        yield 'standard string ends at its quote' => [$path, 'postgresql', 'SELECT * FROM t WHERE p = ? AND id = ?'];
        yield 'MySQL backslash escapes the quote' => [$path, 'mysql', 'SELECT * FROM t WHERE p = ?'];
        yield 'MariaDB in any case' => [$quote, 'MariaDB', 'SELECT * FROM t WHERE n = ? AND id = ?'];
        yield 'standard string keeps the backslash' => [$quote, 'sqlite', 'SELECT * FROM t WHERE n = ? s ?'];
        yield 'E-string escapes in any database' => ["SELECT * FROM t WHERE n = E'it\\'s' AND id = 5", 'postgresql', 'SELECT * FROM t WHERE n = ? AND id = ?'];
        yield 'unknown: an escape' => [$quote, null, 'SELECT * FROM t WHERE n = ? AND id = ?'];
        yield 'unknown: no escape when the literal would never end' => [$path, null, 'SELECT * FROM t WHERE p = ? AND id = ?'];
        yield 'unknown: no escape in the whole query when one literal would never end' => ["SELECT * FROM t WHERE p = 'C:\\' AND n = 'y'", null, 'SELECT * FROM t WHERE p = ? AND n = ?'];
        yield 'ClickHouse escapes' => [$path, 'clickhouse', 'SELECT * FROM t WHERE p = ?'];
        yield 'Spanner by the new name escapes' => [$quote, 'gcp.spanner', 'SELECT * FROM t WHERE n = ? AND id = ?'];
    }

    public static function slowInputs(): iterable
    {
        yield 'unclosed literals' => ['SELECT ' . \str_repeat("'\\", 16_000)];
        yield 'minus signs, read twice' => ['SELECT ' . \str_repeat('a-', 16_000) . "'"];
        yield 'plus signs, read twice' => ['SELECT ' . \str_repeat('1+', 16_000) . "'"];
    }

    public static function cutQueries(): iterable
    {
        $head = "INSERT INTO t (a, b) VALUES ('it\\'s', 'q'), ";
        yield 'cut inside a literal' => [$head . \str_repeat("(1, 'x'), ", 100) . "(2, '" . \str_repeat('y', 40_000) . "')"];
        yield 'cut between values' => [$head . \str_repeat('(1, 2), ', 5_000) . '(3, 4)'];
    }

    #[DataProvider('shapes')]
    public function testShapesQuery(string $sql, string $shape): void
    {
        Assert::same(QueryShape::of($sql), $shape);
    }

    #[DataProvider('dialects')]
    public function testReadsLiteralsByDialect(string $sql, ?string $system, string $shape): void
    {
        Assert::same(QueryShape::of($sql, $system), $shape);
    }

    /**
     * Linear inputs take milliseconds; the bound only catches quadratic work, seconds even without coverage.
     */
    #[DataProvider('slowInputs')]
    public function testLongInputTakesLinearTime(string $sql): void
    {
        $start = \hrtime(true);

        QueryShape::of($sql);

        Assert::numeric((\hrtime(true) - $start) / 1e9)->lessThan(5.0);
    }

    #[DataProvider('cutQueries')]
    public function testEscapeOfCutQueryOfUnknownDatabase(string $sql): void
    {
        Assert::same(QueryShape::of($sql), 'INSERT INTO t(a, b) VALUES (?) …');
    }

    public function testReadsOnlyTheStartOfLongQuery(): void
    {
        $rows = static fn(int $n): string => 'INSERT INTO t (a, b) VALUES ' . \implode(', ', \array_fill(0, 20_000, "($n, 'x$n')"));

        $a = QueryShape::of($rows(1));
        $b = QueryShape::of($rows(12345));

        Assert::same($a, 'INSERT INTO t(a, b) VALUES (?) …');
        Assert::same($b, $a);
        Assert::same(QueryShape::of('SELECT ' . \str_repeat('a, ', 20_000) . 'b'), QueryShape::of('SELECT ' . \str_repeat('a, ', 30_000) . 'c'));
        Assert::string(QueryShape::of(\str_repeat('(', 1_000_000)))->endsWith(' …');
    }

    public function testQueriesThatDifferInValuesShareShape(): void
    {
        $a = QueryShape::of("select * from products where id = 1 and sku in ('a') -- first");
        $b = QueryShape::of("SELECT *\n  FROM products\n WHERE id=200 AND sku IN ('b', 'c', 'd')");

        Assert::same($a, $b);
    }
}
