<?php

namespace WebSocket\Protocol\Struct;

use WebSocket\Protocol\Exception\ProtocolException;
use WebSocket\Protocol\Registry\Opcode;

/**
 * Represents frame value object.
 * @see https://datatracker.ietf.org/doc/html/rfc6455#section-5
 */
readonly class Frame
{
    /**
     * @param bool $isFinal Indicates if this is the final fragment.
     * @param Opcode $opcode Frame opcode.
     * @param string|null $payload Frame data.
     */
    public function __construct(
        public bool $isFinal,
        public Opcode $opcode,
        public ?string $payload = null
    ) {
        if ($opcode->isControl()) {
            if (!$isFinal) {
                throw new ProtocolException("Control frames must not be fragmented", 1002);
            }

            $frameLength = $payload ? strlen($payload) : 0;

            if ($frameLength > 125) {
                throw new ProtocolException("Only non-control frames can have extended length", 1002);
            }
        }
    }

    /**
     * @return string Returns encoded data ready for sending.
     */
    public function __toString(): string
    {
        return $this->encode();
    }

    /**
     * Encodes frame for further sending.
     * @return string Encoded data.
     * @see https://datatracker.ietf.org/doc/html/rfc6455#section-6.1
     */
    public function encode(): string
    {
        // FIN (1 bit) + RSV1, RSV2, RSV3 (1 bit each) + Opcode (4 bits)
        $header = pack('C', ($this->isFinal ? 0b10000000 : 0b00000000) | $this->opcode->value);
        // Payload length
        $frameLength = $this->payload ? strlen($this->payload) : 0;

        if ($frameLength > 65535) {
            // Mask (1 bit) + Payload length (7 bits) + Extended (64 bits)
            $header .= pack('C', 0b00000000 | 127) . pack('J', $frameLength);
        } elseif ($frameLength > 125) {
            // Mask (1 bit) + Payload length (7 bits) + Extended (16 bits)
            $header .= pack('C', 0b00000000 | 126) . pack('n', $frameLength);
        } else {
            // Mask (1 bit) + Payload length (7 bits)
            $header .= pack('C', 0b00000000 | $frameLength);
        }

        return $header . $this->payload;
    }
}
