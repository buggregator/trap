<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\XDebug\Dbgp;

/**
 * Splits a byte stream into DBGp frames as the bytes arrive: engine packets `<length>\0<xml>\0`,
 * or IDE commands `<line>\0`.
 *
 * @internal
 */
final class PacketReader
{
    private string $buffer = '';

    public function __construct(
        private readonly bool $lengthPrefixed = true,
    ) {}

    /**
     * @return list<string> The frames completed by the data.
     *
     * @throws ProtocolError
     */
    public function push(string $data): array
    {
        $this->buffer .= $data;
        $frames = [];
        while (($frame = $this->lengthPrefixed ? $this->packet() : $this->command()) !== null) {
            $frames[] = $frame;
        }

        return $frames;
    }

    private function packet(): ?string
    {
        $zero = \strpos($this->buffer, "\0");
        if ($zero === false) {
            \strlen($this->buffer) > 10 || ($this->buffer !== '' && !\ctype_digit($this->buffer))
                and throw new ProtocolError('Bad packet length.');
            return null;
        }
        $digits = \substr($this->buffer, 0, $zero);
        $digits !== '' && \strlen($digits) <= 10 && \ctype_digit($digits)
            or throw new ProtocolError('Bad packet length.');
        $length = (int) $digits;
        $length <= Protocol::MAX_PACKET or throw new ProtocolError('Packet too large.');
        if (\strlen($this->buffer) < $zero + $length + 2) {
            return null;
        }
        $this->buffer[$zero + 1 + $length] === "\0" or throw new ProtocolError('Packet not NUL-terminated.');
        $body = \substr($this->buffer, $zero + 1, $length);
        $this->buffer = \substr($this->buffer, $zero + $length + 2);

        return $body;
    }

    private function command(): ?string
    {
        $zero = \strpos($this->buffer, "\0");
        if ($zero === false) {
            \strlen($this->buffer) > Protocol::MAX_COMMAND and throw new ProtocolError('Command too large.');
            return null;
        }
        $line = \substr($this->buffer, 0, $zero);
        $this->buffer = \substr($this->buffer, $zero + 1);

        return $line;
    }
}
