<?php

namespace WebSocket\Entity;

use WebSocket\Registry\Opcode;

/**
 * Represents frame header value object
 */
readonly class FrameHeader
{
    /**
     * @param bool $isFinal Indicates if this is the final fragment
     * @param Opcode $opcode Frame opcode
     * @param bool $isMasked Whether the payload data is masked
     * @param int $dataLength Length of the payload data (in bytes)
     * @param int $headerLength Length of the frame header (in bytes)
     */
    public function __construct(
        public bool $isFinal,
        public Opcode $opcode,
        public bool $isMasked,
        public int $dataLength,
        public int $headerLength,
    ) {}

    /**
     * Parses frame header from client's read buffer
     * @param Client $client Client instance
     * @return self|null Returns frame header instance or **NULL** on failure
     */
    public static function parse(Client $client): ?self
    {
        $header = $client->readRaw(2);

        if (mb_strlen($header, '8bit') === 2) {
            $headerLength = 2;
            $bytes = unpack('C2', $header);

            if ($bytes) {
                // FIN (1 bit)
                $isFinal = (bool)($bytes[1] & 0b10000000);

                try {
                    // Opcode (4 bits)
                    $opcode = Opcode::from($bytes[1] & 0b00001111);
                } catch (\Error) {
                    $client->disconnect();
                    return null;
                }

                // Mask (1 bit)
                $isMasked = (bool)($bytes[2] & 0b10000000);
                // Payload length (7 bits)
                $dataLength = $bytes[2] & 0b01111111;

                // Whether control frame or not
                $isControl = $opcode->isControl();
                // Control frames must not be fragmented
                if (!$isFinal && $isControl) {
                    $client->disconnect();
                    return null;
                }

                if ($dataLength > 125) {
                    // Only non-control frames can have extended length
                    if ($isControl) {
                        $client->disconnect();
                        return null;
                    }

                    $extendedLength = null;

                    if ($dataLength === 127) {
                        // Extended payload length (64 bits)
                        $extendedData = $client->readRaw(8, $headerLength);

                        if (mb_strlen($extendedData, '8bit') === 8) {
                            $headerLength += 8;
                            /** @var int|null $extendedLength */
                            $extendedLength = (unpack('J', $extendedData) ?: [1 => null])[1];
                        }
                    } else if ($dataLength === 126) {
                        // Extended payload length (16 bits)
                        $extendedData = $client->readRaw(2, $headerLength);

                        if (mb_strlen($extendedData, '8bit') === 2) {
                            $headerLength += 2;
                            /** @var int|null $extendedLength */
                            $extendedLength = (unpack('n', $extendedData) ?: [1 => null])[1];
                        }
                    }

                    if ($extendedLength !== null) {
                        $dataLength = $extendedLength;
                        return new self($isFinal, $opcode, $isMasked, $dataLength, $headerLength);
                    }
                } else {
                    return new self($isFinal, $opcode, $isMasked, $dataLength, $headerLength);
                }
            }
        }

        return null;
    }
}
