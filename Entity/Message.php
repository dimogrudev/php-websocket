<?php

namespace Entity;

class Message
{
    const OPCODE_CONTINUATION       = 0;
    const OPCODE_TEXT               = 1;
    const OPCODE_BINARY             = 2;
    const OPCODE_CONNECTION_CLOSE   = 8;
    const OPCODE_PING               = 9;
    const OPCODE_PONG               = 10;

    const MAX_CHUNK_LENGTH          = 4096;
    const MAX_CHUNKS                = 16;

    /** @var resource $stream Stream */
    private $stream;

    /** @var int $opcode Opcode */
    private int $opcode;
    /** @var string|null $payload Message content */
    private ?string $payload        = null;
    /** @var int $length Message length */
    private int $length             = 0;
    /** @var bool $final Final message */
    private bool $final             = true;

    /**
     * @param resource $stream
     * @param int|null $opcode
     * @param string|null $payload
     * @param bool $final
     * @return void
     */
    public function __construct($stream, ?int $opcode = null, ?string $payload = null, bool $final = true)
    {
        $this->stream = $stream;

        if ($opcode) {
            $this->opcode = $opcode;
        } else {
            $this->opcode = self::OPCODE_CONTINUATION;
        }
        if ($payload) {
            $this->payload = $payload;
            $this->length = strlen($payload);
        }
        $this->final = $final;
    }

    /**
     * @param resource $stream
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

                            return new self($stream, $opcode, $payload, $final);
                        }
                    } else {
                        return new self($stream, $opcode);
                    }
                }
            }
        }

        return new self($stream, self::OPCODE_CONNECTION_CLOSE);
    }

    /**
     * @param resource $stream
     * @param bool &$final
     * @param int|null &$opcode
     * @param bool &$masked
     * @param int|null &$length
     * @return bool
     */
    private static function receiveHeader($stream, &$final = true, &$opcode = null, &$masked = true, &$length = null): bool
    {
        $header = self::readFromStream($stream, 2);

        if ($header) {
            $bytes = unpack('C2', $header);

            if ($bytes) {
                $final = (bool)($bytes[1] & 0b10000000);
                $opcode = $bytes[1] & 0b00001111;

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
     * @param resource $stream
     * @param int $length
     * @return string|null
     */
    private static function readFromStream($stream, int $length): ?string
    {
        $buffer = '';
        $bufferSize = 0;

        while ($bufferSize < $length) {
            $data = fread($stream, $length - $bufferSize);

            if ($data) {
                $buffer .= $data;
                $bufferSize = strlen($buffer);
            } else {
                return null;
            }
        }

        return $buffer;
    }

    /**
     * @return void
     */
    public function send(): void
    {
        // FIN (1 bit) + RSV1, RSV2, RSV3 (1 bit each) + Opcode (4 bits)
        $header = pack('C', ($this->final ? 0b10000000 : 0b00000000) | $this->opcode);

        // Set payload length
        if ($this->length > 65535) {
            // Mask (1 bit) + Payload length (7+64 bits)
            $header .= pack('CJ', 0b00000000 | 127, $this->length);
        } else if ($this->length > 125) {
            // Mask (1 bit) + Payload length (7+16 bits)
            $header .= pack('Cn', 0b00000000 | 126, $this->length);
        } else {
            // Mask (1 bit) + Payload length (7 bits)
            $header .= pack('C', 0b00000000 | $this->length);
        }

        @fwrite($this->stream, $header . $this->payload);
    }

    /**
     * @return int
     */
    public function getOpcode(): int
    {
        return $this->opcode;
    }

    /**
     * @return string|null
     */
    public function getPayload(): ?string
    {
        return $this->payload;
    }

    /**
     * @return bool
     */
    public function isFinal(): bool
    {
        return $this->final;
    }
}
