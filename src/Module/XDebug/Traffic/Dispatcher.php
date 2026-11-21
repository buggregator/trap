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
            return $rest === \substr('<?xml', 0, \strlen($rest)) ? ($rest === '<?xml' ?: null) : false;
        }

        return $data === '' || (\strlen($data) <= 10 && \ctype_digit($data)) ? null : false;
    }
}
