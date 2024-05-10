<?php

declare(strict_types=1);

namespace Buggregator\Trap\Traffic\Message\Smtp;

/**
 * @internal
 */
enum MessageFormat: string
{
    public function contentType(): string
    {
        return match ($this) {
            self::Plain => 'text/plain',
            self::Html => 'text/html',
            self::Watch => 'text/watch-html',
        };
    }
    case Plain = 'plain';
    case Html = 'html';
    case Watch = 'watch';
}
