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
     * Receives frame from stream
     * @param resource $stream Source stream
     * @param int $maxChunks Maximum amount of data chunks per frame
     * @param int $maxChunkLength Maximum size (in bytes) of each chunk
     * @return self Returns frame instance
     * @see https://datatracker.ietf.org/doc/html/rfc6455#section-6.2
     */
    public static function receive($stream, int $maxChunks = 8, int $maxChunkLength = 1024): self
    {
        $maxFrameLength = $maxChunks * $maxChunkLength;

        if (self::receiveHeader($stream, $final, $opcode, $masked, $length)) {
            if ($length <= $maxFrameLength) {
                $maskingKey = null;
                if ($masked) {
                    $maskingKey = self::readFromStream($stream, 4);
                }

                if (!$masked || ($masked && $maskingKey)) {
                    if ($length > 0) {
                        $buffer = '';

                        $remaining = $length;
                        for ($i = 0; $i < $maxChunks, $remaining > 0; $i++) {
                            $chunk = self::readFromStream($stream, min($remaining, $maxChunkLength));
                            if (!$chunk) {
                                $buffer = '';
                                break;
                            }

                            $buffer .= $chunk;
                            $remaining -= mb_strlen($chunk, '8bit');
                        }

                        if ($buffer) {
                            $payload = '';

                            if ($maskingKey) {
                                for ($i = 0; $i < $length; $i++) {
                                    $payload .= $buffer[$i] ^ $maskingKey[$i % 4];
                                }
                            } else {
                                $payload = $buffer;
                            }

                            return new self($final, $opcode, $payload);
                        }
                    } else {
                        return new self($final, $opcode);
                    }
                }
            }
        }

        return new self(true, Opcode::CLOSE);
    }

    /**
     * Receives frame header from stream
     * @param resource $stream Source stream
     * @param bool &$final Final frame
     * @param Opcode &$opcode Frame opcode
     * @param bool &$masked Frame data is masked
     * @param int &$length Frame data length
     * @return bool Returns **TRUE** on success or **FALSE** otherwise
     */
    private static function receiveHeader($stream, &$final, &$opcode, &$masked, &$length): bool
    {
        $header = self::readFromStream($stream, 2);

        if ($header) {
            $bytes = unpack('C2', $header);

            if ($bytes) {
                // FIN (1 bit)
                $final = (bool)($bytes[1] & 0b10000000);

                try {
                    // Opcode (4 bits)
                    $opcode = Opcode::from($bytes[1] & 0b00001111);
                } catch (\Error) {
                    return false;
                }

                // Mask (1 bit)
                $masked = (bool)($bytes[2] & 0b10000000);
                // Payload length (7 bits)
                $length = $bytes[2] & 0b01111111;

                // Whether control frame or not
                $isControl = $opcode->isControl();
                // Control frames must not be fragmented
                if (!$final && $isControl) {
                    return false;
                }

                if ($length > 125) {
                    // Only non-control frames can have extended length
                    if (!$isControl) {
                        $extendedLength = null;

                        if ($length == 127) {
                            $extendedData = self::readFromStream($stream, 8);
                            if ($extendedData) {
                                // Extended payload length (64 bits)
                                $extendedLength = (unpack('J', $extendedData) ?: [1 => null])[1];
                            }
                        } else if ($length == 126) {
                            $extendedData = self::readFromStream($stream, 2);
                            if ($extendedData) {
                                // Extended payload length (16 bits)
                                $extendedLength = (unpack('n', $extendedData) ?: [1 => null])[1];
                            }
                        }

                        if (is_int($extendedLength)) {
                            $length = $extendedLength;
                            return true;
                        }
                    }
                } else {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Reads data from stream
     * @param resource $stream Source stream
     * @param int $length Data length
     * @return string|null Returns received data or **NULL** on failure
     */
    private static function readFromStream($stream, int $length): ?string
    {
        $buffer = '';
        $bufferSize = 0;

        while ($bufferSize < $length) {
            $data = fread($stream, $length - $bufferSize);

            if ($data) {
                $buffer .= $data;
                $bufferSize += mb_strlen($data, '8bit');
            } else {
                return null;
            }
        }

        return $buffer;
    }

    /**
     * Sends frame
     * @param resource $stream Target stream
     * @return void
     * @see https://datatracker.ietf.org/doc/html/rfc6455#section-6.1
     */
    public function send($stream): void
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

        @fwrite($stream, $header . $this->payload);
    }
}
