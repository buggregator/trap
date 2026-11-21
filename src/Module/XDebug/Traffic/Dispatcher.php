<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\XDebug\Traffic;

use Buggregator\Trap\Module\XDebug\Connection;
use Buggregator\Trap\Module\XDebug\Link\StreamLink;
use Buggregator\Trap\Server\Traffic\Dispatcher as DispatcherInterface;
use Buggregator\Trap\Server\Traffic\StreamClient;

/**
 * Takes the connections of Xdebug in debug mode on any port of Trap: the engine speaks first,
 * with its init packet `<length>\0<?xml`.
 *
 * @internal
 * @psalm-internal Buggregator\Trap
 */
final class Dispatcher implements DispatcherInterface
{
    /** Seconds a client may stop in the middle of what looks like an init packet before it is given up */
    public const SILENCE = 1.0;

    public function __construct(
        private readonly Connection $connection,
    ) {}

    /**
     * The session makes no frames: it lives on the Xdebug page of the UI and in the MCP tools.
     */
    #[\Override]
    public function dispatch(StreamClient $stream): iterable
    {
        $this->connection->run(new StreamLink($stream));

        return [];
    }

    #[\Override]
    public function detect(string $data, float $silence): ?bool
    {
        if (\preg_match('/^(\d{1,10})\0/', $data, $m) === 1) {
            $rest = \substr($data, \strlen($m[0]), 5);
            $prefix = $rest === \substr('<?xml', 0, \strlen($rest));
            return match (true) {
                $prefix && $rest === '<?xml' => true,
                $prefix && $silence <= self::SILENCE => null,
                default => false,
            };
        }

        // Xdebug sends its init packet at once: a client that stops short of it is left to the other dispatchers
        return ($data === '' || (\strlen($data) <= 10 && \ctype_digit($data))) && $silence <= self::SILENCE
            ? null
            : false;
    }
}
