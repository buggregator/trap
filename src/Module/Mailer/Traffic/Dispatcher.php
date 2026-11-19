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
    public const AUTHENTICATED = 235;
    public const BAD_SEQUENCE = 503;
    public const UNKNOWN_MECHANISM = 504;

    /** Seconds of silence after which a connection is taken for SMTP. */
    public const SILENCE = 0.5;

    private Parser $parser;

    public function __construct(
    ) {
        $this->parser = new Parser();
    }

    #[\Override]
    public function dispatch(StreamClient $stream): iterable
    {
        $stream->sendData($this->createResponse(self::READY, 'mailamie'));
        // The mail transaction: reset by EHLO, HELO, RSET and each sent mail
        $protocol = [];
        // The credentials of the session: they hold for every mail until STARTTLS
        $auth = [];
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
                        ? "250-mailamie\r\n250-AUTH PLAIN LOGIN\r\n250 STARTTLS\r\n"
                        : "250-mailamie\r\n250 AUTH PLAIN LOGIN\r\n");
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
                    // The client starts the session over, with EHLO, and authenticates again
                    $protocol = [];
                    $auth = [];
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
                    $message = $this->parser->parseStream([...$protocol, ...$auth], $stream);
                    $stream->sendData($this->createResponse(self::OK));
                    yield new Frame($message, $stream->getCreatedAt());
                    $protocol = [];
                    unset($message);
                    break;
                case 'AUTH':
                    if ($auth !== []) {
                        $stream->sendData($this->createResponse(self::BAD_SEQUENCE, '5.5.1 Already authenticated'));
                    } elseif (isset($protocol['FROM'])) {
                        $stream->sendData($this->createResponse(self::BAD_SEQUENCE, '5.5.1 AUTH not allowed during a mail transaction'));
                    } else {
                        $auth = $this->authenticate($stream, $argument) ?? [];
                    }
                    break;
                case 'VRFY':
                    $stream->sendData($this->createResponse(self::CANNOT_VRFY, '2.5.0 Cannot VRFY user, but will accept message'));
                    break;
                case 'HELP':
                    $stream->sendData($this->createResponse(self::HELP, '2.0.0 Commands: HELO EHLO MAIL RCPT DATA RSET NOOP QUIT AUTH STARTTLS VRFY HELP'));
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

    /**
     * Takes any credentials: the exchange is to show them, not to check them. Without CRAM-MD5, which
     * keeps the password from the server, a client that picks the first mechanism it knows sends one.
     *
     * @return array{AUTH: list{non-empty-string}, AUTHZID?: list{non-empty-string}, USERNAME: list{string}, PASSWORD: list{string}}|null
     *         Null when the exchange failed; its reply is sent.
     */
    private function authenticate(StreamClient $stream, string $argument): ?array
    {
        [$mechanism, $initial] = \explode(' ', $argument, 2) + [1 => null];
        $mechanism = \strtoupper($mechanism);
        $initial === null or $initial = \trim($initial);
        $authzid = '';
        switch ($mechanism) {
            case 'PLAIN':
                $response = $this->decode($stream, $initial ?? $this->challenge($stream, ''));
                if ($response === null) {
                    return null;
                }

                // The authorization identity, the username and the password
                $parts = \explode("\0", $response);
                if (\count($parts) !== 3) {
                    $stream->sendData($this->createResponse(self::SYNTAX_ERROR_IN_PARAMETERS, '5.5.2 Malformed PLAIN response'));
                    return null;
                }

                [$authzid, $username, $password] = $parts;
                break;
            case 'LOGIN':
                $username = $this->decode($stream, $initial ?? $this->challenge($stream, 'Username:'));
                if ($username === null) {
                    return null;
                }

                $password = $this->decode($stream, $this->challenge($stream, 'Password:'));
                if ($password === null) {
                    return null;
                }
                break;
            case '':
                $stream->sendData($this->createResponse(self::SYNTAX_ERROR_IN_PARAMETERS, '5.5.4 Syntax: AUTH mechanism'));
                return null;
            default:
                $stream->sendData($this->createResponse(self::UNKNOWN_MECHANISM, '5.5.4 Unrecognized authentication type'));
                return null;
        }

        $stream->sendData($this->createResponse(self::AUTHENTICATED, '2.7.0 Authentication successful'));

        // An authorization identity equal to the username, as Symfony Mailer sends it, says the same as none
        $acting = $authzid === '' || $authzid === $username ? [] : ['AUTHZID' => [$authzid]];

        return ['AUTH' => [$mechanism], ...$acting, 'USERNAME' => [$username], 'PASSWORD' => [$password]];
    }

    /**
     * @param string $prompt Sent base64-encoded, as the mechanism defines it.
     * @return string|null The response line, null when the connection ends.
     */
    private function challenge(StreamClient $stream, string $prompt): ?string
    {
        $stream->sendData('334 ' . \base64_encode($prompt) . "\r\n");
        $line = $stream->fetchLine();

        return $line === '' ? null : \trim($line);
    }

    /**
     * @return string|null The decoded response, null when the client cancelled or sent no base64; the reply is sent.
     */
    private function decode(StreamClient $stream, ?string $response): ?string
    {
        if ($response === null) {
            return null;
        }

        if ($response === '*') {
            $stream->sendData($this->createResponse(self::SYNTAX_ERROR_IN_PARAMETERS, '5.7.0 Authentication cancelled'));
            return null;
        }

        // An initial response of `=` stands for an empty one
        $decoded = $response === '=' ? '' : \base64_decode($response, true);
        if ($decoded === false) {
            $stream->sendData($this->createResponse(self::SYNTAX_ERROR_IN_PARAMETERS, '5.5.2 Cannot decode response'));
            return null;
        }

        return $decoded;
    }

    private function createResponse(int $statusCode, string|null $comment = null): string
    {
        return $comment === null ? "{$statusCode} \r\n" : "{$statusCode} {$comment}\r\n";
    }
}
