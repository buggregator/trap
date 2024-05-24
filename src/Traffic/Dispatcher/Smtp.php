<?php

declare(strict_types=1);

namespace Buggregator\Trap\Traffic\Dispatcher;

use Buggregator\Trap\Proto\Frame;
use Buggregator\Trap\Traffic\StreamClient;
use Buggregator\Trap\Traffic\Dispatcher;
use Buggregator\Trap\Traffic\Parser;

/**
 * @internal
 * @psalm-internal Buggregator\Trap
 */
final class Smtp implements Dispatcher
{
    private const READY = 220;

    public const OK = 250;

    public const CLOSING = 221;

    public const START_MAIL_INPUT = 354;

    private Parser\Smtp $parser;

    public function __construct(
    ) {
        $this->parser = new Parser\Smtp();
    }

    public function dispatch(StreamClient $stream): iterable
    {
        $stream->sendData($this->createResponse(self::READY, 'mailamie'));
        $protocol = [];

        $message = null;
        while (!$stream->isFinished()) {
            $response = $stream->fetchLine();
            if (\preg_match('/^(?:EHLO|HELO)/', $response)) {
                $stream->sendData($this->createResponse(self::OK));
            } elseif (\preg_match('/^MAIL FROM:\s*<(.*)>/', $response, $matches)) {
                /** @var array{0: non-empty-string, 1: string} $matches */
                $protocol['FROM'][] = $matches[1];
                $stream->sendData($this->createResponse(self::OK));
            } elseif (\preg_match('/^RCPT TO:\s*<(.*)>/', $response, $matches)) {
                /** @var array{0: non-empty-string, 1: string} $matches */
                $protocol['BCC'][] = $matches[1];
                $stream->sendData($this->createResponse(self::OK));
            } elseif (\str_starts_with($response, 'QUIT')) {
                $stream->sendData($this->createResponse(self::CLOSING));
                $stream->disconnect();
            } elseif (\str_starts_with($response, 'DATA')) {
                $stream->sendData($this->createResponse(self::START_MAIL_INPUT));

                $message = $this->parser->parseStream($protocol, $stream);
                $stream->sendData($this->createResponse(self::OK));
            }
        }

        if ($message === null) {
            return;
        }

        yield new Frame\Smtp($message, $stream->getCreatedAt());
    }

    public function detect(string $data, \DateTimeImmutable $createdAt): ?bool
    {
        if ($data !== '') {
            return false;
        }

        $interval = $createdAt->diff(new \DateTimeImmutable());
        return $interval->f > 0.5 ? true : null;
    }

    private function createResponse(int $statusCode, string|null $comment = null): string
    {
        $response = \implode(' ', \array_filter([$statusCode, $comment]));

        return "{$response} \r\n";
    }
}
