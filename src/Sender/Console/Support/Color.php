<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender\Console\Support;

/**
 * Default terminal colors
 *
 * @internal
 * @psalm-internal Buggregator\Trap\Sender\Console
 */
enum Color: string
{
    case Black = 'black';
    case Red = 'red';
    case Green = 'green';
    case Yellow = 'yellow';
    case Blue = 'blue';
    case Magenta = 'magenta';
    case Cyan = 'cyan';
    case White = 'white';
    case Gray = 'gray'; // can be unsupported in some terminals
    case Default = 'default';
}
