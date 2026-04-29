<?php

namespace WebSocket\Entity;

use WebSocket\Registry\Opcode;

/**
 * Represents frame entity
 * @see https://datatracker.ietf.org/doc/html/rfc6455#section-5
 */
class Frame
{
    /**
     * @param bool $final Final frame
     * @param Opcode $opcode Frame opcode
     * @param string|null $payload Frame data
     * @return void
     */
    public function __construct(
        private(set) bool $final,
        private(set) Opcode $opcode,
        private(set) ?string $payload = null
    ) {}

    /**
     * Parses frame from client's read buffer
     * @param Client $client Client instance
     * @return self|null Returns frame instance or **NULL** on failure
     * @see https://datatracker.ietf.org/doc/html/rfc6455#section-6.2
     */
    public static function parse(Client $client): ?self
    {
        /**
         * @var bool $isFinal
         * @var Opcode $opcode
         * @var bool $isMasked
         * @var int $dataLength
         */
        $headerLength = self::parseHeader($client, $isFinal, $opcode, $isMasked, $dataLength);

        if ($headerLength !== null) {
            $maskingKey = null;
            $maskLength = 0;

            if ($isMasked) {
                $maskingKey = $client->readRaw(4, $headerLength);

                if (mb_strlen($maskingKey, '8bit') === 4) {
                    $maskLength = 4;
                } else {
                    return null;
                }
            }

            if ($dataLength > 0) {
                $buffer = $client->readRaw($dataLength, $headerLength + $maskLength);

                if (mb_strlen($buffer, '8bit') === $dataLength) {
                    $payload = '';

                    if ($maskingKey !== null) {
                        for ($i = 0; $i < $dataLength; $i++) {
                            $payload .= $buffer[$i] ^ $maskingKey[$i % 4];
                        }
                    } else {
                        $payload = $buffer;
                    }

                    $client->discardReadData($headerLength + $maskLength + $dataLength);
                    return new self($isFinal, $opcode, $payload);
                }
            } else {
                $client->discardReadData($headerLength + $maskLength);
                return new self($isFinal, $opcode);
            }
        }

        return null;
    }

    /**
     * Parses frame header from client's read buffer
     * @param Client $client Client instance
     * @param bool &$final Final frame
     * @param Opcode &$opcode Frame opcode
     * @param bool &$masked Frame data is masked
     * @param int &$length Frame data length
     * @return int|null Returns header length (in bytes) or **NULL** on failure
     */
    private static function parseHeader(Client $client, &$final, &$opcode, &$masked, &$length): ?int
    {
        $header = $client->readRaw(2);

        if (mb_strlen($header, '8bit') === 2) {
            $headerLength = 2;
            $bytes = unpack('C2', $header);

            if ($bytes) {
                // FIN (1 bit)
                $final = (bool)($bytes[1] & 0b10000000);

                try {
                    // Opcode (4 bits)
                    $opcode = Opcode::from($bytes[1] & 0b00001111);
                } catch (\Error) {
                    $client->disconnect();
                    return null;
                }

                // Mask (1 bit)
                $masked = (bool)($bytes[2] & 0b10000000);
                // Payload length (7 bits)
                $length = $bytes[2] & 0b01111111;

                // Whether control frame or not
                $isControl = $opcode->isControl();
                // Control frames must not be fragmented
                if (!$final && $isControl) {
                    $client->disconnect();
                    return null;
                }

                if ($length > 125) {
                    // Only non-control frames can have extended length
                    if ($isControl) {
                        $client->disconnect();
                        return null;
                    }

                    $extendedLength = null;

                    if ($length === 127) {
                        // Extended payload length (64 bits)
                        $extendedData = $client->readRaw(8, $headerLength);

                        if (mb_strlen($extendedData, '8bit') === 8) {
                            $headerLength += 8;
                            /** @var int|null $extendedLength */
                            $extendedLength = (unpack('J', $extendedData) ?: [1 => null])[1];
                        }
                    } else if ($length === 126) {
                        // Extended payload length (16 bits)
                        $extendedData = $client->readRaw(2, $headerLength);

                        if (mb_strlen($extendedData, '8bit') === 2) {
                            $headerLength += 2;
                            /** @var int|null $extendedLength */
                            $extendedLength = (unpack('n', $extendedData) ?: [1 => null])[1];
                        }
                    }

                    if ($extendedLength !== null) {
                        $length = $extendedLength;
                        return $headerLength;
                    }
                } else {
                    return $headerLength;
                }
            }
        }

        return null;
    }

    /**
     * Encodes frame for further sending
     * @return string Encoded data
     * @see https://datatracker.ietf.org/doc/html/rfc6455#section-6.1
     */
    public function encode(): string
    {
        // FIN (1 bit) + RSV1, RSV2, RSV3 (1 bit each) + Opcode (4 bits)
        $header = pack('C', ($this->final ? 0b10000000 : 0b00000000) | $this->opcode->value);
        // Payload length
        $frameLength = $this->payload ? mb_strlen($this->payload, '8bit') : 0;

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
