<?php

declare(strict_types=1);

namespace Buggregator\Client\Traffic\Message\Smtp;

enum MessageFormat: string
{
    case Plain = 'plain';
    case Html = 'html';
    case Watch = 'watch';

    public function contentType(): string
    {
        return match ($this) {
            self::Plain => 'text/plain',
            self::Html => 'text/html',
            self::Watch => 'text/watch-html',
        };
    }
}
