<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Mailer\Traffic;

use Buggregator\Trap\Module\Mailer\Frame;
use Buggregator\Trap\Server\Traffic\Dispatcher as DispatcherInterface;
use Buggregator\Trap\Server\Traffic\StreamClient;

/**
 * @internal
 * @psalm-internal Buggregator\Trap
 */
final class Dispatcher implements DispatcherInterface
{
    private const READY = 220;
    public const OK = 250;
    public const CLOSING = 221;
    public const START_MAIL_INPUT = 354;
    public const TLS_UNAVAILABLE = 454;

    /** Seconds of silence after which a connection is taken for SMTP. */
    public const SILENCE = 0.5;

    private Parser $parser;

    public function __construct(
    ) {
        $this->parser = new Parser();
    }

    // todo support Auth https://www.rfc-editor.org/rfc/rfc4954
    #[\Override]
    public function dispatch(StreamClient $stream): iterable
    {
        $stream->sendData($this->createResponse(self::READY, 'mailamie'));
        $protocol = [];
        while (!$stream->isFinished()) {
            $response = \ltrim($stream->fetchLine(), ' ');
            if (\str_starts_with($response, 'NOOP')) {
                $stream->sendData($this->createResponse(self::OK));
            } elseif (\str_starts_with($response, 'EHLO')) {
                $stream->sendData($stream->canStartTls()
                    ? "250-mailamie\r\n250 STARTTLS\r\n"
                    : $this->createResponse(self::OK));
                $protocol = [];
            } elseif (\preg_match('/^(?:HELO|RSET)/', $response)) {
                $stream->sendData($this->createResponse(self::OK));
                $protocol = [];
            } elseif (\str_starts_with($response, 'STARTTLS')) {
                if (!$stream->canStartTls()) {
                    $stream->sendData($this->createResponse(self::TLS_UNAVAILABLE, 'TLS not available'));
                    continue;
                }

                $stream->sendData($this->createResponse(self::READY, 'Ready to start TLS'));
                $stream->startTls();
                // The client starts the session over, with EHLO
                $protocol = [];
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
                return;
            } elseif (\str_starts_with($response, 'DATA')) {
                $stream->sendData($this->createResponse(self::START_MAIL_INPUT));
                $message = $this->parser->parseStream($protocol, $stream);
                $stream->sendData($this->createResponse(self::OK));
                yield new Frame($message, $stream->getCreatedAt());
                $protocol = [];
                unset($message);
            }
        }
    }

    #[\Override]
    public function detect(string $data, float $silence): ?bool
    {
        if ($data !== '') {
            return false;
        }

        // An SMTP client waits for the server greeting
        return $silence > self::SILENCE ? true : null;
    }

    private function createResponse(int $statusCode, string|null $comment = null): string
    {
        $response = \implode(' ', \array_filter([$statusCode, $comment]));

        return "{$response} \r\n";
    }
}
