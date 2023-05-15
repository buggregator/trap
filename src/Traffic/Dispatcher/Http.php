<?php

declare(strict_types=1);

namespace Buggregator\Client\Traffic\Dispatcher;

use Buggregator\Client\Logger;
use Buggregator\Client\Proto\Frame;
use Buggregator\Client\ProtoType;
use Buggregator\Client\Socket\StreamClient;
use Buggregator\Client\Traffic\Dispatcher;
use Buggregator\Client\Traffic\Http\HandlerPipeline;
use Buggregator\Client\Traffic\Http\HttpParser;
use DateTimeImmutable;

final class Http implements Dispatcher
{
    public function __construct(
        private readonly HandlerPipeline $handler,
    ) {
    }

    public function dispatch(StreamClient $stream): iterable
    {
        Logger::debug('Got http');

        $request = HttpParser::parseStream((static function (StreamClient $stream) {
            while (!$stream->isFinished()) {
                yield $stream->fetchLine();
            }
        })($stream));

        $response = $this->handler->handle($request);

        $stream->sendData((string)$response);

        yield new Frame(
            new DateTimeImmutable(),
            ProtoType::HTTP,
            $str = $stream->fetchAll(),
        );
        Logger::debug($str);
    }

    public function detect(string $data): ?bool
    {
        if (!\str_contains($data, "\r\n")) {
            return null;
        }

        if (\preg_match('/^(GET|POST|PUT|HEAD|OPTIONS) \\S++ HTTP\\/1\\.\\d\\r$/m', $data) === 1) {
            Logger::info('THIS IS HTTP!');
            return true;
        }

        Logger::info($data);

        return false;
    }
}
