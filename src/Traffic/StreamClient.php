<?php

declare(strict_types=1);

namespace Buggregator\Trap\Traffic;

use Buggregator\Trap\Support\Timer;
use Generator;

/**
 * Simple abstraction over a client two-way stream.
 * @internal
 * @psalm-internal Buggregator\Trap
 * @extends \IteratorAggregate<int, string>
 */
interface StreamClient extends \IteratorAggregate
{
    public function getCreatedAt(): \DateTimeImmutable;

    public function hasData(): bool;

    public function waitData(?Timer $timer = null): void;

    /**
     * @return bool Return {@see false} if data is empty or stream was closed.
     */
    public function sendData(string $data): bool;

    public function disconnect(): void;

    /**
     * @return bool Return {@see true} if stream was closed.
     */
    public function isDisconnected(): bool;

    /**
     * @return bool Return {@see true} if there will be no more data.
     */
    public function isFinished(): bool;

    /**
     * Returns {@see string} with trailing EOL or without it if stream was closed.
     * Uses {@see Fiber} to wait for EOL|EOF.
     */
    public function fetchLine(): string;

    /**
     * Uses {@see Fiber} to wait for all data.
     */
    public function fetchAll(): string;

    /**
     * Get read data without waiting and without cleaning.
     */
    public function getData(): string;

    /**
     * Iterate all data by read chunks using {@see Generator} until {@see self::isDisconnected()}.
     * Cleans cache.
     * Uses {@see Fiber} to wait for all data.
     *
     * @return \Generator<int, string, mixed, void>
     */
    public function getIterator(): \Generator;
}
