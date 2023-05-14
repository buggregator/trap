<?php

declare(strict_types=1);

namespace Buggregator\Client\Traffic\Dispatcher;

use Buggregator\Client\Proto\Frame;
use Buggregator\Client\ProtoType;
use Buggregator\Client\Socket\StreamClient;
use Buggregator\Client\Traffic\Dispatcher;
use DateTimeImmutable;

final class Smtp implements Dispatcher
{
    private const READY = 220;
    public const OK = 250;
    public const CLOSING = 221;
    public const START_MAIL_INPUT = 354;


    public function dispatch(StreamClient $stream): iterable
    {
        $stream->sendData($this->createResponse(self::READY, 'mailamie'));

        $message = [];

        while (($response = $stream->fetchLine()) !== '') {
            if (\preg_match('/^(EHLO|HELO|MAIL FROM:)/', $response)) {
                $stream->sendData($this->createResponse(self::OK));
            } elseif (\preg_match('/^RCPT TO:<(.*)>/', $response, $matches)) {
                $message['recipients'][] = $matches[0];
                $stream->sendData($this->createResponse(self::OK));
            } elseif (\str_starts_with($response, 'QUIT')) {
                $stream->sendData($this->createResponse(self::CLOSING));
                // DISCONNECT
            } elseif (\str_starts_with($response, 'DATA')) {
                $stream->sendData($this->createResponse(self::START_MAIL_INPUT));
                $content = '';

                do {
                    $response = $stream->fetchLine();
                    $content .= \preg_replace("/^(\.\.)/m", '.', $response);
                } while (!$this->endOfContentDetected($response));

                $stream->sendData($this->createResponse(self::CLOSING));

                $message['content'] = $content;
                break;
            }
        }

        yield new Frame(
            new DateTimeImmutable(),
            ProtoType::SMTP,
            \json_encode($message),
        );
    }

    public function detect(string $data): ?bool
    {
        return $data === '';
    }

    private function createResponse(int $statusCode, string|null $comment = null): string
    {
        $response = \implode(' ', \array_filter([$statusCode, $comment]));

        return "{$response} \r\n";
    }

    private function endOfContentDetected(string $data): bool
    {
        return $data === ".\r\n";
    }
}
