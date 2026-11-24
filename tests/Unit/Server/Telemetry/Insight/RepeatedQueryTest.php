<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Server\Telemetry\Insight;

use Buggregator\Trap\Server\Telemetry\Insight\Finding;
use Buggregator\Trap\Server\Telemetry\Insight\RepeatedQuery;
use Buggregator\Trap\Server\Telemetry\Peer;
use Buggregator\Trap\Server\Telemetry\Source;
use Buggregator\Trap\Server\Telemetry\Span;
use Buggregator\Trap\Tests\Unit\Server\Telemetry\Insight\Stub\Traces;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Test;

#[Test]
#[Covers(RepeatedQuery::class)]
final class RepeatedQueryTest
{
    #[DataSet([4, 0], 'one below the threshold')]
    #[DataSet([5, 1], 'at the threshold')]
    #[DataSet([30, 1], 'above it')]
    public function testReportsQueryRepeatedFiveTimesOrMore(int $repeats, int $findings): void
    {
        $trace = Traces::trace([Traces::transaction('txn', 'r')]);
        for ($i = 0; $i < $repeats; ++$i) {
            Traces::add($trace, Traces::db("q$i", 'r', 'txn', "SELECT * FROM products WHERE id = $i"));
        }

        Assert::count((new RepeatedQuery())->check($trace), $findings);
    }

    public function testDescribesRepeatedSentryQuery(): void
    {
        $trace = Traces::trace([Traces::transaction('txn', 'r', 'GET /cart')]);
        Traces::add($trace, Traces::db('other', 'r', 'txn', 'SELECT * FROM carts WHERE id = 1'));
        for ($i = 1; $i <= 5; ++$i) {
            $attributes = $i >= 3 ? ['code.filepath' => "/app/Cart$i.php", 'code.lineno' => 30 + $i] : [];
            // A power of two: a float of Unix seconds holds it exactly
            Traces::add($trace, Traces::db("q$i", 'r', 'txn', "SELECT * FROM products WHERE id = $i", 2 ** -9, $attributes));
        }

        $findings = (new RepeatedQuery())->check($trace);

        Assert::count($findings, 1);
        Assert::same(self::plain($findings[0]), [
            'rule' => 'n_plus_one',
            'transactionId' => 'txn',
            'spanIds' => ['q1', 'q2', 'q3', 'q4', 'q5'],
            'summary' => 'SELECT * FROM products WHERE id = ?',
            'count' => 5,
            'totalMs' => 9.766,
            'origin' => '/app/Cart3.php:33',
        ]);
    }

    public function testGroupsByTransaction(): void
    {
        $trace = Traces::trace([Traces::transaction('a', 'ra'), Traces::transaction('b', 'rb')]);
        for ($i = 0; $i < 6; ++$i) {
            Traces::add($trace, Traces::db("a$i", 'ra', 'a', 'SELECT 1'));
            Traces::add($trace, Traces::db("b$i", 'rb', 'b', 'SELECT 1'));
        }

        $findings = (new RepeatedQuery())->check($trace);

        Assert::same(\array_map(static fn(Finding $f): ?string => $f->transactionId, $findings), ['a', 'b']);
    }

    public function testOpenTelemetrySpanBelongsToTransactionUpItsParents(): void
    {
        $trace = Traces::trace([Traces::transaction('trace-root', 'root', 'GET /orders', Source::Otel)]);
        Traces::add($trace, Traces::span('repo', 'root', null, 'internal', 'OrderRepository::load', Source::Otel));
        for ($i = 0; $i < 5; ++$i) {
            Traces::add($trace, Traces::db("q$i", 'repo', null, 'SELECT shop.items', attributes: [
                'db.system.name' => 'postgresql',
                'db.query.text' => "SELECT * FROM items WHERE order_id = \$1 /* $i */",
                'code.file.path' => 'src/OrderRepository.php',
                'code.line.number' => 12,
            ], source: Source::Otel));
        }

        $findings = (new RepeatedQuery())->check($trace);

        Assert::count($findings, 1);
        Assert::same($findings[0]->transactionId, 'trace-root');
        Assert::same($findings[0]->summary, 'SELECT * FROM items WHERE order_id = ?');
        Assert::same($findings[0]->origin, 'src/OrderRepository.php:12');
    }

    public function testReadsOlderStatementAttribute(): void
    {
        $trace = Traces::trace([Traces::transaction('txn', 'r')]);
        for ($i = 0; $i < 5; ++$i) {
            Traces::add($trace, Traces::db("q$i", 'r', 'txn', 'SELECT', attributes: ['db.statement' => "SELECT name FROM users WHERE id = $i"]));
        }

        $findings = (new RepeatedQuery())->check($trace);

        Assert::same($findings[0]->summary, 'SELECT name FROM users WHERE id = ?');
    }

    public function testShapesEachSpanOnceWhileItLives(): void
    {
        $rule = new RepeatedQuery();
        /** @var \WeakMap<Span, string> $shapes */
        $shapes = (new \ReflectionProperty(RepeatedQuery::class, 'shapes'))->getValue($rule);
        $trace = Traces::trace([Traces::transaction('txn', 'r')]);
        for ($i = 0; $i < 5; ++$i) {
            Traces::add($trace, Traces::db("q$i", 'r', 'txn', "SELECT * FROM t WHERE id = $i"));
        }
        $rule->check($trace);
        foreach ($trace->spans as $span) {
            $shapes[$span] = 'shaped before';
        }

        $findings = $rule->check($trace);
        unset($trace->spans['q0'], $span);

        Assert::same($findings[0]->summary, 'shaped before');
        Assert::count($shapes, 4);
    }

    public function testSiblingsOfParentNotArrivedCountTogether(): void
    {
        $trace = Traces::trace();
        for ($i = 0; $i < 6; ++$i) {
            Traces::add($trace, Traces::db("q$i", 'server-span', null, "SELECT * FROM items WHERE id = $i", source: Source::Otel));
        }
        Traces::add($trace, Traces::db('other', 'another-parent', null, 'SELECT * FROM items WHERE id = 9'));

        $findings = (new RepeatedQuery())->check($trace);

        Assert::count($findings, 1);
        Assert::same($findings[0]->spanIds, ['q0', 'q1', 'q2', 'q3', 'q4', 'q5']);
        Assert::null($findings[0]->transactionId);
    }

    #[DataSet(['db.redis', []], 'Sentry Redis span')]
    #[DataSet(['db', ['db.system' => 'redis']], 'OTel Redis span')]
    #[DataSet(['db', ['db.system.name' => 'MongoDB']], 'MongoDB')]
    #[DataSet(['db.query', ['db.system' => 'memcached']], 'Memcached')]
    public function testSkipsStoresThatTakeNoSql(string $op, array $attributes): void
    {
        $trace = Traces::trace([Traces::transaction('txn', 'r')]);
        for ($i = 0; $i < 5; ++$i) {
            Traces::add($trace, Traces::span("c$i", 'r', 'txn', $op, "GET user:$i", attributes: $attributes, peer: new Peer('db')));
        }

        Assert::same((new RepeatedQuery())->check($trace), []);
    }

    #[DataSet(['postgresql', 'SELECT * FROM files WHERE path = ? AND id = ?'], 'standard strings')]
    #[DataSet(['mysql', 'SELECT * FROM files WHERE path = ?'], 'backslash escapes')]
    public function testReadsLiteralsInTheDialectOfTheDatabase(string $system, string $shape): void
    {
        $trace = Traces::trace([Traces::transaction('txn', 'r')]);
        for ($i = 0; $i < 5; ++$i) {
            Traces::add($trace, Traces::db("q$i", 'r', 'txn', "SELECT * FROM files WHERE path = 'C:\\' AND id = $i", attributes: ['db.system' => $system]));
        }

        $findings = (new RepeatedQuery())->check($trace);

        Assert::same($findings[0]->summary, $shape);
    }

    public function testSpansGroupUnderMissingParentUpTheirParents(): void
    {
        $trace = Traces::trace();
        Traces::add($trace, Traces::span('job', 'missing', null, 'queue.process', 'SendMail'));
        for ($i = 0; $i < 5; ++$i) {
            Traces::add($trace, Traces::db("q$i", 'job', null, 'SELECT * FROM mails WHERE id = ?'));
        }

        $findings = (new RepeatedQuery())->check($trace);

        Assert::count($findings, 1);
        Assert::null($findings[0]->transactionId);
        Assert::null($findings[0]->origin);
    }

    public function testIgnoresSpansThatAreNoQueries(): void
    {
        $trace = Traces::trace([Traces::transaction('txn', 'r')]);
        for ($i = 0; $i < 5; ++$i) {
            Traces::add($trace, Traces::span("c$i", 'r', 'txn', 'cache.get', 'SELECT * FROM cache', peer: new Peer('cache')));
            Traces::add($trace, Traces::db("e$i", 'r', 'txn', '/* empty */'));
        }

        Assert::same((new RepeatedQuery())->check($trace), []);
    }

    public function testParentCycleEnds(): void
    {
        $trace = Traces::trace();
        Traces::add($trace, Traces::span('x', 'y', null, 'internal', 'x'));
        Traces::add($trace, Traces::span('y', 'x', null, 'internal', 'y'));
        for ($i = 0; $i < 5; ++$i) {
            Traces::add($trace, Traces::db("q$i", 'x', null, 'SELECT 1'));
        }

        $findings = (new RepeatedQuery())->check($trace);

        Assert::count($findings, 1);
        Assert::null($findings[0]->transactionId);
    }

    private static function plain(Finding $finding): array
    {
        return \get_object_vars($finding);
    }
}
