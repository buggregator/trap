<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Server\Telemetry\Insight\Stub;

use Buggregator\Trap\Server\Telemetry\Peer;
use Buggregator\Trap\Server\Telemetry\Source;
use Buggregator\Trap\Server\Telemetry\Span;
use Buggregator\Trap\Server\Telemetry\Trace;
use Buggregator\Trap\Server\Telemetry\Transaction;

/**
 * Transactions and spans of one trace, the spans 1 ms long by default.
 */
final class Traces
{
    public const ID = '5b8efff798038103d269b633813fc60c';

    /**
     * @param list<Transaction> $transactions
     */
    public static function trace(array $transactions = []): Trace
    {
        $trace = new Trace(self::ID);
        foreach ($transactions as $transaction) {
            $trace->transactions[$transaction->id] = $transaction;
        }

        return $trace;
    }

    public static function add(Trace $trace, Span $span): Trace
    {
        $trace->spans[$span->spanId] = $span;

        return $trace;
    }

    /**
     * @param non-empty-string $id
     */
    public static function transaction(
        string $id,
        string $spanId,
        string $name = 'GET /',
        Source $source = Source::Sentry,
        string $project = 'shop',
    ): Transaction {
        return new Transaction($id, $project, self::ID, $spanId, null, $name, 'http.server', 'ok', 1_700_000_000.0, 1_700_000_001.0, $source, 'api');
    }

    /**
     * @param non-empty-string $id
     */
    public static function db(
        string $id,
        ?string $parent,
        ?string $transactionId,
        string $query,
        float $seconds = 0.001,
        array $attributes = [],
        Source $source = Source::Sentry,
        string $project = 'shop',
    ): Span {
        return self::span($id, $parent, $transactionId, 'db.sql.query', $query, $source, $seconds, $attributes, new Peer('db'), $project);
    }

    /**
     * @param non-empty-string $id
     */
    public static function span(
        string $id,
        ?string $parent,
        ?string $transactionId,
        string $op,
        string $description,
        Source $source = Source::Sentry,
        float $seconds = 0.001,
        array $attributes = [],
        Peer $peer = new Peer(),
        string $project = 'shop',
    ): Span {
        $start = 1_700_000_000.25;

        return new Span($project, self::ID, $id, $parent, $transactionId, $op, $description, 'ok', $start, $start + $seconds, $source, 'api', $peer, $attributes);
    }
}
