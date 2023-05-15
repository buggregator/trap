<?php

declare(strict_types=1);

namespace Buggregator\Client\Traffic\Dispatcher;

use Buggregator\Client\Logger;
use Buggregator\Client\Proto\Frame;
use Buggregator\Client\ProtoType;
use Buggregator\Client\Socket\StreamClient;
use Buggregator\Client\Traffic\Dispatcher;
use Buggregator\Client\Traffic\Http\HttpParser;
use DateTimeImmutable;

final class Http implements Dispatcher
{
    private HttpParser $parser;

    public function __construct() {
        $this->parser = new HttpParser();
    }

    public function dispatch(StreamClient $stream): iterable
    {
        Logger::debug('Got http');

        $request = $this->parser->parseStream((static function (StreamClient $stream) {
            while (!$stream->isFinished()) {
                yield $stream->fetchLine();
            }
        })($stream));

        $stream->sendData(
            <<<Response
                HTTP/1.1 200 Bad Request\r
                Date: Sun, 18 Oct 2012 10:36:20 GMT\r
                Server: Apache/2.2.14 (Win32)\r
                Content-Type: text/html; charset=iso-8859-1\r
                Connection: Closed\r
                \r
                Foo bar\r\n
                Response
        );

        $stream->disconnect();

        // todo process request

        yield new Frame(
            new DateTimeImmutable(),
            ProtoType::HTTP,
            \json_encode($request, JSON_THROW_ON_ERROR),
        );
    }

    public function detect(string $data): ?bool
    {
        if (!\str_contains($data, "\r\n")) {
            return null;
        }

        if (\preg_match('/^(GET|POST|PUT|HEAD|OPTIONS) \\S++ HTTP\\/1\\.\\d\\r$/m', $data) === 1) {
            return true;
        }

        Logger::info($data);

        return false;
    }
}
