<?php

declare(strict_types=1);

namespace Buggregator\Trap\Traffic\Websocket;

/**
 *  0                   1                   2                   3
 *  0 1 2 3 4 5 6 7 8 9 0 1 2 3 4 5 6 7 8 9 0 1 2 3 4 5 6 7 8 9 0 1
 * +-+-+-+-+-------+-+-------------+-------------------------------+
 * |F|R|R|R| opcode|M| Payload len |    Extended payload length    |
 * |I|S|S|S|  (4)  |A|     (7)     |             (16/64)           |
 * |N|V|V|V|       |S|             |   (if payload len==126/127)   |
 * | |1|2|3|       |K|             |                               |
 * +-+-+-+-+-------+-+-------------+ - - - - - - - - - - - - - - - +
 * |     Extended payload length continued, if payload len == 127  |
 * + - - - - - - - - - - - - - - - +-------------------------------+
 * |                               |Masking-key, if MASK set to 1  |
 * +-------------------------------+-------------------------------+
 * | Masking-key (continued)       |          Payload Data         |
 * +-------------------------------- - - - - - - - - - - - - - - - +
 * :                     Payload Data continued ...                :
 * + - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - +
 * |                     Payload Data continued ...                |
 * +---------------------------------------------------------------+
 *
 *   FIN:  1 bit
 *
 *      Indicates that this is the final fragment in a message.  The first
 *      fragment MAY also be the final fragment.
 *
 *   RSV1, RSV2, RSV3:  1 bit each
 *
 *      MUST be 0 unless an extension is negotiated that defines meanings
 *      for non-zero values.  If a nonzero value is received and none of
 *      the negotiated extensions defines the meaning of such a nonzero
 *      value, the receiving endpoint MUST _Fail the WebSocket
 *      Connection_.
 *   Opcode:  4 bits
 *
 *      Defines the interpretation of the "Payload data".  If an unknown
 *      opcode is received, the receiving endpoint MUST _Fail the
 *      WebSocket Connection_.  The following values are defined.
 *
 *      *  %x0 denotes a continuation frame
 *      *  %x1 denotes a text frame
 *      *  %x2 denotes a binary frame
 *      *  %x3-7 are reserved for further non-control frames
 *      *  %x8 denotes a connection close
 *      *  %x9 denotes a ping
 *      *  %xA denotes a pong
 *      *  %xB-F are reserved for further control frames
 *
 *   Mask:  1 bit
 *
 *      Defines whether the "Payload data" is masked.  If set to 1, a
 *      masking key is present in masking-key, and this is used to unmask
 *      the "Payload data" as per [Section 5.3]
 *      (https://datatracker.ietf.org/doc/html/rfc6455#section-5.3).
 *      All frames sent from client to server have this bit set to 1.
 *
 *   Payload length:  7 bits, 7+16 bits, or 7+64 bits
 *
 *      The length of the "Payload data", in bytes: if 0-125, that is the
 *      payload length.  If 126, the following 2 bytes interpreted as a
 *      16-bit unsigned integer are the payload length.  If 127, the
 *      following 8 bytes interpreted as a 64-bit unsigned integer (the
 *      most significant bit MUST be 0) are the payload length.  Multibyte
 *      length quantities are expressed in network byte order.  Note that
 *      in all cases, the minimal number of bytes MUST be used to encode
 *      the length, for example, the length of a 124-byte-long string
 *      can't be encoded as the sequence 126, 0, 124.  The payload length
 *      is the length of the "Extension data" + the length of the
 *      "Application data".  The length of the "Extension data" may be
 *      zero, in which case the payload length is the length of the
 *      "Application data".
 *
 *   Masking-key:  0 or 4 bytes
 *
 *      All frames sent from the client to the server are masked by a
 *      32-bit value that is contained within the frame.  This field is
 *      present if the mask bit is set to 1 and is absent if the mask bit
 *      is set to 0.  See [Section 5.3]
 *      (https://datatracker.ietf.org/doc/html/rfc6455#section-5.3)
 *      for further information on client-to-server masking.
 *
 *   Payload data:  (x+y) bytes
 *
 *      The "Payload data" is defined as "Extension data" concatenated
 *      with "Application data".
 *
 *   Extension data:  x bytes
 *
 *      The "Extension data" is 0 bytes unless an extension has been
 *      negotiated.  Any extension MUST specify the length of the
 *      "Extension data", or how that length may be calculated, and how
 *      the extension use MUST be negotiated during the opening handshake.
 *      If present, the "Extension data" is included in the total payload
 *      length.
 *
 *   Application data:  y bytes
 *
 *      Arbitrary "Application data", taking up the remainder of the frame
 *      after any "Extension data".  The length of the "Application data"
 *      is equal to the payload length minus the length of the "Extension
 *      data".
 *
 * @link https://datatracker.ietf.org/doc/html/rfc6455#section-5.2
 *
 * @internal
 */
final class Frame implements \Stringable
{
    private function __construct(
        public readonly string $content,
        public readonly Opcode $opcode,
        public readonly bool $fin = true,
        public readonly bool $rsv1 = false,
    ) {
    }

    /**
     * Parse row client frame and extract body.
     */
    public static function read(string $content): self
    {
        $fin = (bool)(\ord($content[0]) & 128);
        $opcode = Opcode::from(\ord($content[0]) & 0x0f);
        $len = \ord($content[1]) & 127;
        $isMask = (bool)(\ord($content[1]) & 128);
        $rsv1 = (bool)(\ord($content[0]) & 64);

        if ($len === 126) {
            $len = \unpack('n', \substr($content, 2, 2))[1];
            $maskOffset = 4;
        } elseif ($len === 127) {
            $len = \unpack('J', \substr($content, 2, 8))[1];
            $maskOffset = 10;
        } else {
            $maskOffset = 2;
        }

        $mask = $isMask ? \substr($content, $maskOffset, 4) : null;
        $body = \substr($content, $maskOffset + ($isMask ? 4 : 0), $len);

        // Apply mask
        if ($isMask) {
            for ($i = 0; $i < $len; ++$i) {
                $body[$i] = \chr(\ord($body[$i]) ^ \ord($mask[$i % 4]));
            }
        }

        return new self(
            $body,
            $opcode,
            $fin,
            $rsv1,
        );
    }

    public static function text(string $content): self
    {
        return new self($content, Opcode::Text);
    }

    public static function ping(): self
    {
        return new self('', Opcode::Ping);
    }

    public static function pong(): self
    {
        return new self('', Opcode::Pong);
    }

    public static function close(): self
    {
        return new self('', Opcode::Close);
    }

    public function __toString(): string
    {
        $len = \strlen($this->content);

        return \sprintf(
            '%s%s%s',
            \chr(128 | $this->opcode->value),
            match (true) {
                $len < 126 => \chr($len),
                $len < 65536 => \pack('Cn', 126, $len),
                default => \pack('CJ', 127, $len),
            },
            $this->content,
        );
    }
}
