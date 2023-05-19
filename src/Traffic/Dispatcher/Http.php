<?php

declare(strict_types=1);

namespace Buggregator\Client\Traffic\Dispatcher;

use Buggregator\Client\Logger;
use Buggregator\Client\Proto\Frame;
use Buggregator\Client\Socket\StreamClient;
use Buggregator\Client\Traffic\Dispatcher;
use Buggregator\Client\Traffic\Http\HttpParser;

final class Http implements Dispatcher
{
    private HttpParser $parser;

    public function __construct() {
        $this->parser = new HttpParser();
    }

    public function dispatch(StreamClient $stream): iterable
    {
        $request = $this->parser->parseStream((static function (StreamClient $stream) {
            // Submit cached data first
            foreach (\explode("\n", $stream->getData()) as $line) {
                yield $line . "\n";
            }
            // Then read from the stream
            while (!$stream->isFinished()) {
                yield $stream->fetchLine();
            }
        })($stream));

        $stream->sendData(
            <<<Response
                HTTP/1.1 200 OK\r
                Date: Sun, 18 Oct 2012 10:36:20 GMT\r
                Server: Apache/2.2.14 (Win32)\r
                Content-Type: text/html; charset=iso-8859-1\r
                Connection: Closed\r
                \r
                <html lang="en"><body>
                    <form method="post" action="/foo/bar?get=test&hello=world" enctype='multipart/form-data'>
                        <span>Test form</span>
                        <br /><input type="text" name="name" value="Actor"/>
                        <br /><textarea name="message">Hello World!</textarea>
                        <br /><input type="file" name="files" multiple />
                        <br /><input type="submit" />
                    </form>
                </body></html>\r\n\r\n
                Response
        );

        $stream->disconnect();

        // todo process request

        yield new Frame\Http(
            $request
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
