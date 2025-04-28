<?php

namespace Entity;

use Enum\Opcode;

/**
 * Represents message entity
 */
final class Message
{
    const int MAX_CHUNK_LENGTH      = 4096;
    const int MAX_CHUNKS            = 16;

    /**
     * Class constructor
     * @param resource $stream Stream
     * @param bool $final Final message
     * @param Opcode $opcode Message opcode
     * @param string|null $payload Message content
     * @return void
     */
    public function __construct(
        private mixed $stream,
        private(set) bool $final,
        private(set) Opcode $opcode,
        private(set) ?string $payload = null
    ) {}

    /**
     * Receives message from stream 
     * @param resource $stream Source stream
     * @return self
     */
    public static function receive($stream): self
    {
        if (self::receiveHeader($stream, $final, $opcode, $masked, $length)) {
            if ($opcode !== null && $length !== null) {
                $maskingKey = null;
                if ($masked) {
                    $maskingKey = self::readFromStream($stream, 4);
                }

                if (!$masked || ($masked && $maskingKey)) {
                    if ($length > 0) {
                        $buffer = '';

                        $remaining = $length;
                        for ($i = 0; $i < self::MAX_CHUNKS, $remaining > 0; $i++) {
                            $chunk = self::readFromStream($stream, min($remaining, self::MAX_CHUNK_LENGTH));
                            if (!$chunk) {
                                $buffer = '';
                                break;
                            }

                            $buffer .= $chunk;
                            $remaining -= strlen($chunk);
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

                            return new self($stream, $final, $opcode, $payload);
                        }
                    } else {
                        return new self($stream, true, $opcode);
                    }
                }
            }
        }

        return new self($stream, true, Opcode::CONNECTION_CLOSE);
    }

    /**
     * Receives header from stream
     * @param resource $stream Source stream
     * @param bool &$final Final message
     * @param Opcode|null &$opcode Message opcode
     * @param bool &$masked Message content is masked
     * @param int|null &$length Message content length
     * @return bool Returns **TRUE** on success, **FALSE** otherwise
     */
    private static function receiveHeader($stream, &$final = true, &$opcode = null, &$masked = true, &$length = null): bool
    {
        $header = self::readFromStream($stream, 2);

        if ($header) {
            $bytes = unpack('C2', $header);

            if ($bytes) {
                $final = (bool)($bytes[1] & 0b10000000);
                $opcode = Opcode::tryFrom($bytes[1] & 0b00001111);

                $masked = (bool)($bytes[2] & 0b10000000);
                $length = $bytes[2] & 0b01111111;

                if ($length > 125) {
                    $extendedLength = null;

                    if ($length == 127) {
                        $extendedData = self::readFromStream($stream, 8);
                        if ($extendedData) {
                            $extendedLength = (unpack('J', $extendedData) ?: [1 => null])[1];
                        }
                    } else if ($length == 126) {
                        $extendedData = self::readFromStream($stream, 2);
                        if ($extendedData) {
                            $extendedLength = (unpack('n', $extendedData) ?: [1 => null])[1];
                        }
                    }

                    $length = $extendedLength;

                    if ($length !== null) {
                        return true;
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
                $bufferSize += strlen($data);
            } else {
                return null;
            }
        }

        return $buffer;
    }

    /**
     * Sends message
     * @return void
     */
    public function send(): void
    {
        // FIN (1 bit) + RSV1, RSV2, RSV3 (1 bit each) + Opcode (4 bits)
        $header = pack('C', ($this->final ? 0b10000000 : 0b00000000) | $this->opcode->value);
        // Payload length
        $msgLength = is_string($this->payload) ? strlen($this->payload) : 0;

        // Set payload length
        if ($msgLength > 65535) {
            // Mask (1 bit) + Payload length (7+64 bits)
            $header .= pack('CJ', 0b00000000 | 127, $msgLength);
        } else if ($msgLength > 125) {
            // Mask (1 bit) + Payload length (7+16 bits)
            $header .= pack('Cn', 0b00000000 | 126, $msgLength);
        } else {
            // Mask (1 bit) + Payload length (7 bits)
            $header .= pack('C', 0b00000000 | $msgLength);
        }

        @fwrite($this->stream, $header . $this->payload);
    }
}
