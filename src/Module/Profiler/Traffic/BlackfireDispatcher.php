<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Profiler\Traffic;

use Buggregator\Trap\Module\Profiler\Blackfire\ProfileBuilder;
use Buggregator\Trap\Module\Profiler\BuildLock;
use Buggregator\Trap\Module\Profiler\Compaction\Report;
use Buggregator\Trap\Module\Profiler\Frame;
use Buggregator\Trap\Module\Profiler\Struct\Profile;
use Buggregator\Trap\Server\Logger;
use Buggregator\Trap\Server\Traffic\Dispatcher;
use Buggregator\Trap\Server\Traffic\StreamClient;
use Buggregator\Trap\Support\TimeSlice;

/**
 * Takes the profiles of the Blackfire probe on any port of Trap, in place of the agent the probe sends them to.
 *
 * The probe speaks first: `Blackfire-Query: <query>` and more header lines, up to an empty line.
 * Trap answers `Blackfire-Response: continue=true` and an empty line; the probe sends the profile
 * and closes the connection.
 *
 * Without a query, the probe opens a connection for its monitoring on a web request, starting with
 * `file-format: BlackfireApm`: Trap closes it unanswered, and the probe stops trying for a while.
 *
 * @internal
 * @psalm-internal Buggregator\Trap
 */
final class BlackfireDispatcher implements Dispatcher
{
    /** Seconds a client may stop in the middle of the first line before it is given up */
    public const SILENCE = 1.0;

    /** Size limit of a profile in bytes */
    public const MAX_SIZE = 64 * 1024 * 1024;

    private const MARKER = 'Blackfire-Query: ';
    private const APM_MARKER = 'file-format: BlackfireApm';

    /** Size limit of the header lines of the query in bytes */
    private const MAX_QUERY_SIZE = 64 * 1024;

    public function __construct(
        private readonly ProfileBuilder $profileBuilder,
        private readonly Logger $logger,
        private readonly BuildLock $lock,
    ) {}

    #[\Override]
    public function dispatch(StreamClient $stream): iterable
    {
        if (\str_starts_with($stream->getData(), self::APM_MARKER)) {
            $stream->disconnect();
            return [];
        }

        $query = self::readQuery($stream);
        if ($query === null) {
            $this->logger->warning('Blackfire: the probe closed the connection or sent a malformed query.');
            $stream->disconnect();
            return [];
        }

        $stream->sendData("Blackfire-Response: continue=true\n\n");

        try {
            $data = self::readProfile($stream);
            // The lock is taken once the profile is read: a slow probe holds no other build
            $profile = $this->lock->run(fn(): Profile => $this->profileBuilder->createProfile(
                self::lines($data),
                $query,
                timeSlice: new TimeSlice(),
            ));
        } catch (\Throwable $e) {
            $this->logger->warning('Blackfire: the profile is rejected: %s', \lcfirst($e->getMessage()));
            $stream->disconnect();
            return [];
        } finally {
            isset($data) and \fclose($data);
        }

        /** @var mixed $compacted */
        $compacted = $profile->metadata['compacted'] ?? null;
        \is_array($compacted) and $this->logger->warning(
            'Blackfire: the profile is compacted to %d call tree nodes: %s.',
            (int) ($compacted['limit'] ?? 0),
            Report::summarize($compacted),
        );

        return [
            new Frame(
                payload: Frame\Payload::new(
                    type: Frame\Type::Blackfire,
                    callsProvider: static fn(): Profile => $profile,
                ),
                time: $stream->getCreatedAt(),
            ),
        ];
    }

    #[\Override]
    public function detect(string $data, float $silence): ?bool
    {
        if (\str_starts_with($data, self::MARKER) || \str_starts_with($data, self::APM_MARKER)) {
            return true;
        }

        // The probe sends its first line at once: a client that stops short of it is left to the other dispatchers
        return (\str_starts_with(self::MARKER, $data) || \str_starts_with(self::APM_MARKER, $data))
            && $silence <= self::SILENCE ? null : false;
    }

    /**
     * @return array<array-key, mixed>|null The parameters of the query; null when the stream ends before
     *         the empty line, or the header lines are too large or have no query.
     */
    private static function readQuery(StreamClient $stream): ?array
    {
        // Waited for before the lines are fetched: a line is buffered whole, however long it runs
        while (($end = self::emptyLine($data = $stream->getData())) === null) {
            if (\strlen($data) > self::MAX_QUERY_SIZE || $stream->isDisconnected()) {
                return null;
            }
            $stream->waitData();
        }
        if ($end > self::MAX_QUERY_SIZE) {
            return null;
        }

        $query = null;
        do {
            $line = $stream->fetchLine();
            $line = \rtrim($line, "\r\n");
            \str_starts_with($line, self::MARKER) and $query = \substr($line, \strlen(self::MARKER));
        } while ($line !== '');

        if ($query === null) {
            return null;
        }

        \parse_str($query, $params);
        return $params;
    }

    /**
     * @return int|null The offset of the empty line that ends the header lines, if the data has it.
     */
    private static function emptyLine(string $data): ?int
    {
        return \preg_match('/\r?\n\r?\n/', $data, $match, \PREG_OFFSET_CAPTURE) === 1 ? $match[0][1] : null;
    }

    /**
     * @return resource A temporary stream with the profile, rewound.
     *
     * @throws \LengthException When the profile is over {@see self::MAX_SIZE}.
     */
    private static function readProfile(StreamClient $stream): mixed
    {
        $data = \fopen('php://temp', 'w+b') ?: throw new \RuntimeException('Unable to create a temporary stream.');
        $size = 0;
        foreach ($stream->getIterator() as $chunk) {
            $size += \strlen($chunk);
            if ($size > self::MAX_SIZE) {
                \fclose($data);
                throw new \LengthException(\sprintf('The profile is over %d MB.', self::MAX_SIZE >> 20));
            }
            \fwrite($data, $chunk);
        }
        \rewind($data);

        return $data;
    }

    /**
     * @param resource $data
     *
     * @return \Generator<int, string>
     */
    private static function lines(mixed $data): \Generator
    {
        while (($line = \fgets($data)) !== false) {
            yield $line;
        }
    }
}
