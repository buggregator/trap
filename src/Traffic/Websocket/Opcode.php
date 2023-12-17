<?php

declare(strict_types=1);

namespace Buggregator\Trap\Traffic\Websocket;

/**
 * Opcode: 4 bits
 *
 * Defines the interpretation of the "Payload data".  If an unknown opcode is received, the receiving endpoint MUST
 * Fail the WebSocket Connection.  The following values are defined.
 *  %x0 denotes a continuation frame
 *  %x1 denotes a text frame
 *  %x2 denotes a binary frame
 *  %x3-7 are reserved for further non-control frames
 *  %x8 denotes a connection close
 *  %x9 denotes a ping
 *  %xA denotes a pong
 *  %xB-F are reserved for further control frames
 *
 * @link https://datatracker.ietf.org/doc/html/rfc6455#section-5.2
 *
 * @internal
 * @psalm-internal Buggregator\Trap
 */
enum Opcode: int
{
    case Continuation = 0x00;
    case Text = 0x01;
    case Binary = 0x02;
    case Close = 0x08;
    case Ping = 0x09;
    case Pong = 0x0A;
}
