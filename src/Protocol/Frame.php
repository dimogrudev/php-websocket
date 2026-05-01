<?php

namespace WebSocket\Protocol;

use WebSocket\Client;
use WebSocket\Registry\Opcode;

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
    ) {}

    /**
     * @return string Returns encoded data ready for sending.
     */
    public function __toString(): string
    {
        return $this->encode();
    }

    /**
     * Parses frame from client's read buffer.
     * @param Client $client Client instance.
     * @return self|null Returns frame instance or **NULL** on failure.
     * @see https://datatracker.ietf.org/doc/html/rfc6455#section-6.2
     */
    public static function parse(Client $client): ?self
    {
        $header = FrameHeader::parse($client);

        if ($header !== null) {
            $maskingKey = null;
            $maskLength = 0;

            if ($header->isMasked) {
                $maskingKey = $client->readRaw(4, $header->headerLength);

                if (strlen($maskingKey) === 4) {
                    $maskLength = 4;
                } else {
                    return null;
                }
            }

            if ($header->dataLength > 0) {
                $buffer = $client->readRaw($header->dataLength, $header->headerLength + $maskLength);

                if (strlen($buffer) === $header->dataLength) {
                    $payload = ($maskingKey !== null)
                        ? self::unmask($buffer, $maskingKey)
                        : $buffer;

                    $client->discardReadData($header->headerLength + $maskLength + $header->dataLength);
                    return new self($header->isFinal, $header->opcode, $payload);
                }
            } else {
                $client->discardReadData($header->headerLength + $maskLength);
                return new self($header->isFinal, $header->opcode);
            }
        }

        return null;
    }

    /**
     * Applies XOR masking to the data.
     * @param string $data Raw data.
     * @param string $maskingKey 4-byte masking key.
     * @return string Returns unmasked data.
     */
    private static function unmask(string $data, string $maskingKey): string
    {
        $unmasked = '';
        $length = strlen($data);

        for ($i = 0; $i < $length; $i++) {
            $unmasked .= $data[$i] ^ $maskingKey[$i % 4];
        }

        return $unmasked;
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

        // Set payload length
        if ($frameLength > 65535) {
            // Mask (1 bit) + Payload length (7 bits) + Extended (64 bits)
            $header .= pack('CJ', 0b00000000 | 127, $frameLength);
        } else if ($frameLength > 125) {
            // Mask (1 bit) + Payload length (7 bits) + Extended (16 bits)
            $header .= pack('Cn', 0b00000000 | 126, $frameLength);
        } else {
            // Mask (1 bit) + Payload length (7 bits)
            $header .= pack('C', 0b00000000 | $frameLength);
        }

        return $header . $this->payload;
    }
}
