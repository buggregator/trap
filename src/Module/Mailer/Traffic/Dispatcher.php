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
    public const HELP = 214;
    public const CANNOT_VRFY = 252;
    public const SYNTAX_ERROR = 500;
    public const SYNTAX_ERROR_IN_PARAMETERS = 501;
    public const NOT_IMPLEMENTED = 502;

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
            $line = $stream->fetchLine();
            if ($line === '') {
                continue;
            }

            [$verb, $argument] = \explode(' ', \trim($line), 2) + [1 => ''];
            $argument = \trim($argument);
            switch (\strtoupper($verb)) {
                case 'NOOP':
                    $stream->sendData($this->createResponse(self::OK));
                    break;
                case 'EHLO':
                    $stream->sendData($stream->canStartTls()
                        ? "250-mailamie\r\n250 STARTTLS\r\n"
                        : $this->createResponse(self::OK));
                    $protocol = [];
                    break;
                case 'HELO':
                case 'RSET':
                    $stream->sendData($this->createResponse(self::OK));
                    $protocol = [];
                    break;
                case 'STARTTLS':
                    if (!$stream->canStartTls()) {
                        $stream->sendData($this->createResponse(self::TLS_UNAVAILABLE, 'TLS not available'));
                        break;
                    }

                    $stream->sendData($this->createResponse(self::READY, 'Ready to start TLS'));
                    $stream->startTls();
                    // The client starts the session over, with EHLO
                    $protocol = [];
                    break;
                case 'MAIL':
                    if (\preg_match('/^FROM:\s*<([^>]*)>/i', $argument, $matches) !== 1) {
                        $stream->sendData($this->createResponse(self::SYNTAX_ERROR_IN_PARAMETERS, '5.5.4 Syntax: MAIL FROM:<address>'));
                        break;
                    }

                    $protocol['FROM'][] = $matches[1];
                    $stream->sendData($this->createResponse(self::OK));
                    break;
                case 'RCPT':
                    if (\preg_match('/^TO:\s*<([^>]*)>/i', $argument, $matches) !== 1) {
                        $stream->sendData($this->createResponse(self::SYNTAX_ERROR_IN_PARAMETERS, '5.5.4 Syntax: RCPT TO:<address>'));
                        break;
                    }

                    $protocol['BCC'][] = $matches[1];
                    $stream->sendData($this->createResponse(self::OK));
                    break;
                case 'QUIT':
                    $stream->sendData($this->createResponse(self::CLOSING));
                    $stream->disconnect();
                    return;
                case 'DATA':
                    $stream->sendData($this->createResponse(self::START_MAIL_INPUT));
                    $message = $this->parser->parseStream($protocol, $stream);
                    $stream->sendData($this->createResponse(self::OK));
                    yield new Frame($message, $stream->getCreatedAt());
                    $protocol = [];
                    unset($message);
                    break;
                case 'VRFY':
                    $stream->sendData($this->createResponse(self::CANNOT_VRFY, '2.5.0 Cannot VRFY user, but will accept message'));
                    break;
                case 'HELP':
                    $stream->sendData($this->createResponse(self::HELP, '2.0.0 Commands: HELO EHLO MAIL RCPT DATA RSET NOOP QUIT STARTTLS VRFY HELP'));
                    break;
                case '':
                    $stream->sendData($this->createResponse(self::SYNTAX_ERROR, '5.5.2 Syntax error, command unrecognized'));
                    break;
                default:
                    // A command left unanswered keeps the client waiting until its timeout
                    $stream->sendData($this->createResponse(self::NOT_IMPLEMENTED, '5.5.2 Command not implemented'));
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
        return $comment === null ? "{$statusCode} \r\n" : "{$statusCode} {$comment}\r\n";
    }
}
