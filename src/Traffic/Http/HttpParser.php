<?php

declare(strict_types=1);

namespace Buggregator\Client\Traffic\Http;

use Buggregator\Client\Logger;

class HttpParser
{
    /**
     * @param \Generator<int, string, mixed, void> $generator Generator that yields lines only with trailing \r\n
     */
    public static function parseStream(\Generator $generator): Request
    {
        $firstLine = $generator->current();
        $generator->next();

        $headers = self::getBlock($generator);

        Logger::debug($firstLine);
        Logger::debug($headers);

        $requset = new Request(
            method: $headers,
            uri: $headers,
            protocol: '',
            headers: [],
            body: '',
        );


        return $requset;
    }

    /**
     * Get text block before empty line
     */
    private static function getBlock(\Generator $generator): string
    {
        $block = '';
        while ($generator->valid()) {
            $line = $generator->current();
            $generator->next();
            if ($line === "\r\n") {
                break;
            }

            $block .= $line;
        }

        return $block;
    }
}
