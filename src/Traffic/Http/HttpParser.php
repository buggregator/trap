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

        $headersBlock = self::getBlock($generator);

        Logger::debug($headersBlock);

        [$method, $uri, $protocol] = self::parseFirstLine($firstLine);
        $headers = self::parseHeaders($headersBlock);

        // todo parse body

        $requset = new Request(
            method: $method,
            uri: $uri,
            protocol: $protocol,
            headers: $headers,
            body: '',
        );

        return $requset;
    }

    /**
     * @param string $line
     *
     * @return array{0: non-empty-string, 1: non-empty-string, 2: non-empty-string}
     */
    private static function parseFirstLine(string $line): array
    {
        $parts = \explode(' ', \trim($line));
        if (\count($parts) !== 3) {
            throw new \InvalidArgumentException('Invalid first line.');
        }

        return $parts;
    }

    /**
     * Get text block before empty line
     *
     * @param \Generator<int, string, mixed, void> $generator
     *
     * @return string
     */
    private static function getBlock(\Generator $generator): string
    {
        $block = '';
        while ($generator->valid()) {
            $line = $generator->current();
            if ($line === "\r\n") {
                break;
            }
            $generator->next();

            $block .= $line;
        }

        return $block;
    }

    /**
     * @param non-empty-string $headersBlock
     *
     * @return array<non-empty-string, list<non-empty-string>>
     */
    private static function parseHeaders(string $headersBlock): array
    {
        $result = [];
        foreach (\explode("\r\n", $headersBlock) as $line) {
            if (!\str_contains($line, ':')) {
                continue;
            }

            [$name, $value] = \explode(':', $line, 2);

            $result[\strtolower(\trim($name))][] = \trim($value);
        }

        return $result;
    }
}
