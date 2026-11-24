<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Telemetry\Insight;

use Buggregator\Trap\Server\Telemetry\Insight\Sql\QueryShape;
use Buggregator\Trap\Server\Telemetry\Span;
use Buggregator\Trap\Server\Telemetry\Trace;

/**
 * The N+1 pattern: one transaction runs the same query, up to its values, {@see self::MIN_COUNT} times or more,
 * as a loop that loads a relation row by row does.
 *
 * Reads the SQL spans: the database spans by {@see \Buggregator\Trap\Server\Telemetry\Peer}, but for Redis
 * (`db.redis`) and the other stores of {@see self::NOT_SQL}, whose commands a loop of cache reads repeats too. The
 * query is the OTel `db.query.text` or the older `db.statement` attribute, else the span description: Sentry SDKs
 * put the query there, OTel only a low-cardinality name such as `SELECT shop.products`. Its dialect is the
 * `db.system.name` or `db.system` attribute.
 *
 * A span without a transaction of its own, as an OTLP or a Sentry v2 span, belongs to the transaction up its
 * parents. Spans whose transaction has not arrived count under the span they hang from: the parent missing from
 * the trace, as the server span that an OTel batch exports after its children, else the topmost one.
 *
 * One instance serves all the traces: the shapes stay with their spans, so a growing trace shapes only its new ones.
 *
 * @internal
 */
final class RepeatedQuery implements TraceRule
{
    public const NAME = 'n_plus_one';
    public const MIN_COUNT = 5;

    /** Parent links followed to find the transaction of a span */
    private const MAX_DEPTH = 256;

    /** The `db.system` names of the databases that take no SQL */
    private const NOT_SQL = [
        'redis', 'memcached', 'mongodb', 'elasticsearch', 'opensearch', 'cassandra', 'couchbase', 'couchdb',
        'dynamodb', 'aws.dynamodb', 'cosmosdb', 'azure.cosmosdb', 'neo4j', 'hbase', 'geode', 'coherence',
    ];

    /** @var \WeakMap<Span, string> */
    private \WeakMap $shapes;

    public function __construct()
    {
        /** @var \WeakMap<Span, string> $shapes */
        $shapes = new \WeakMap();
        $this->shapes = $shapes;
    }

    #[\Override]
    public function check(Trace $trace): array
    {
        $transactionOfSpan = [];
        foreach ($trace->transactions as $transaction) {
            $transaction->spanId === null or $transactionOfSpan[$transaction->spanId] = $transaction->id;
        }

        /** @var array<string, array{non-empty-string|null, non-empty-string}> $groups Transaction ID and shape */
        $groups = [];
        /** @var array<string, non-empty-list<non-empty-string>> $spanIds */
        $spanIds = [];
        /** @var array<string, float> $ms */
        $ms = [];
        /** @var array<string, non-empty-string> $origins */
        $origins = [];
        foreach ($trace->spans as $span) {
            if ($span->peer->type !== 'db') {
                continue;
            }

            $shape = $this->shapes[$span] ??= self::shape($span);
            if ($shape === '') {
                continue;
            }

            [$owner, $transactionId] = self::owner($trace, $span, $transactionOfSpan);
            $key = $owner . "\0" . $shape;
            $groups[$key] ??= [$transactionId, $shape];
            $spanIds[$key][] = $span->spanId;
            $ms[$key] = ($ms[$key] ?? 0.0) + self::durationMs($span);
            if (!isset($origins[$key])) {
                $origin = self::origin($span);
                $origin === null or $origins[$key] = $origin;
            }
        }

        $findings = [];
        foreach ($groups as $key => [$transactionId, $shape]) {
            $count = \count($spanIds[$key]);
            if ($count >= self::MIN_COUNT) {
                $findings[] = new Finding(
                    rule: self::NAME,
                    transactionId: $transactionId,
                    spanIds: $spanIds[$key],
                    summary: $shape,
                    count: $count,
                    totalMs: \round($ms[$key], 3),
                    origin: $origins[$key] ?? null,
                );
            }
        }

        return $findings;
    }

    /**
     * @return string Empty for a span that holds no SQL query.
     */
    private static function shape(Span $span): string
    {
        $system = self::attribute($span, 'db.system.name', 'db.system');
        if ($span->op === 'db.redis' || ($system !== null && \in_array(\strtolower($system), self::NOT_SQL, true))) {
            return '';
        }

        return QueryShape::of(self::attribute($span, 'db.query.text', 'db.statement') ?? $span->description, $system);
    }

    /**
     * @return string|null The first of the attributes that is a string of more than blanks.
     */
    private static function attribute(Span $span, string ...$keys): ?string
    {
        foreach ($keys as $key) {
            /** @var mixed $value */
            $value = $span->attributes[$key] ?? null;
            if (\is_string($value) && \trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * The group of a span: its transaction, else the missing parent it hangs from, else its topmost span.
     *
     * @param array<array-key, non-empty-string> $transactionOfSpan Transaction IDs by their span IDs.
     * @return array{non-empty-string, non-empty-string|null} The group key and the transaction ID.
     */
    private static function owner(Trace $trace, Span $span, array $transactionOfSpan): array
    {
        $top = $span;
        for ($depth = 0; $depth < self::MAX_DEPTH; ++$depth) {
            if ($top->transactionId !== null && $top->transactionId !== '') {
                return ["t:{$top->transactionId}", $top->transactionId];
            }

            $parentId = $top->parentSpanId;
            if ($parentId === null || $parentId === $top->spanId) {
                break;
            }

            if (isset($transactionOfSpan[$parentId])) {
                return ["t:{$transactionOfSpan[$parentId]}", $transactionOfSpan[$parentId]];
            }

            $parent = $trace->spans[$parentId] ?? null;
            if ($parent === null) {
                return ["p:$parentId", null];
            }
            $top = $parent;
        }

        return ["s:{$top->spanId}", null];
    }

    /**
     * To a microsecond: a repeated query often takes less than the whole millisecond of {@see Span::durationMs()}.
     */
    private static function durationMs(Span $span): float
    {
        if ($span->start === null || $span->end === null || $span->end <= $span->start) {
            return 0.0;
        }

        return \min(($span->end - $span->start) * 1000.0, (float) Span::MAX_DURATION_MS);
    }

    /**
     * @return non-empty-string|null `file:line` from the OTel code attributes, old or new; the file alone
     *         without a line.
     */
    private static function origin(Span $span): ?string
    {
        $file = $span->attributes['code.filepath'] ?? $span->attributes['code.file.path'] ?? null;
        if (!\is_string($file) || $file === '') {
            return null;
        }

        /** @var mixed $line */
        $line = $span->attributes['code.lineno'] ?? $span->attributes['code.line.number'] ?? null;

        return \is_int($line) || (\is_string($line) && \ctype_digit($line)) ? "$file:$line" : $file;
    }
}
