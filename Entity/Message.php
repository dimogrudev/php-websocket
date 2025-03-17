<?php

namespace Entity;

class Message
{
    const OPCODE_CONTINUATION_FRAME     = 0;
    const OPCODE_TEXT_FRAME             = 1;
    const OPCODE_BINARY_FRAME           = 2;
    const OPCODE_CONNECTION_CLOSE_FRAME = 8;
    const OPCODE_PING_FRAME             = 9;
    const OPCODE_PONG_FRAME             = 10;

    const MAX_CHUNK_LENGTH              = 8192;
    const MAX_CHUNKS                    = 16;

    /** @var resource $stream Stream */
    private $stream;

    /** @var int $opcode Opcode */
    private int $opcode;
    /** @var string|null $text Message content */
    private ?string $text;
    /** @var int $length Message length */
    private int $length                 = 0;
    /** @var bool $final Final message */
    private bool $final                 = true;

    /**
     * @param resource $stream
     * @param int|null $opcode
     * @param string|null $text
     * @return void
     */
    public function __construct($stream, ?int $opcode = null, ?string $text = null)
    {
        $this->stream = $stream;

        $this->opcode = ($opcode !== null) ? $opcode : self::OPCODE_CONTINUATION_FRAME;
        $this->text = $text;
        if ($text) {
            $this->length = strlen($text);
        }
    }

    public function read(): void
    {
        $frame = fread($this->stream, self::MAX_CHUNK_LENGTH);

        if (!$frame) {
            $this->opcode = self::OPCODE_CONNECTION_CLOSE_FRAME;
            return;
        }

        $this->opcode = @ord($frame[0]) & 15;
        $this->length = @ord($frame[1]) & 127;
        $this->final = (bool)(@ord($frame[0]) & 128);

        if (!$this->length) {
            $this->opcode = self::OPCODE_CONNECTION_CLOSE_FRAME;
            return;
        }

        $dataOffset = 2;

        if ($this->length == 126) {
            $this->length = unpack('n', substr($frame, 2, 2))[1];
            $dataOffset = 4;
        } elseif ($this->length == 127) {
            $this->length = unpack('J', substr($frame, 2, 8))[1];
            $dataOffset = 10;
        }

        $masks = substr($frame, $dataOffset, 4);
        $data = substr($frame, $dataOffset + 4, $this->length);

        $remaining = $this->length - strlen($data);
        for ($i = 0; $i < self::MAX_CHUNKS, $remaining > 0; $i++) {
            $chunk = fread($this->stream, min($remaining, self::MAX_CHUNK_LENGTH));

            $data .= $chunk;
            $remaining -= strlen($chunk);
        }

        $this->text = '';
        for ($i = 0; $i < $this->length; $i++) {
            $this->text .= $data[$i] ^ $masks[$i % 4];
        }
    }

    public function write(): bool
    {
        if ($this->text) {
            $header = [];

            if ($this->opcode == self::OPCODE_PONG_FRAME) {
                $header[] = 138;
            } else if ($this->opcode == self::OPCODE_PING_FRAME) {
                $header[] = 137;
            } else {
                $header[] = 129;
            }

            if ($this->length <= 125) {
                $header[] = $this->length;
            } else if ($this->length <= 65535) {
                $header[] = 126;
                $header[] = ($this->length >> 8) & 0xFF;
                $header[] = $this->length & 0xFF;
            } else {
                $header[] = 127;
                for ($i = 7; $i >= 0; $i--) {
                    $header[] = ($this->length >> ($i * 8)) & 0xFF;
                }
            }

            $data = implode(array_map('chr', $header)) . $this->text;
            return @fwrite($this->stream, $data) !== false;
        }

        return false;
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
    public function getText(): ?string
    {
        return $this->text;
    }

    /**
     * @return bool
     */
    public function isFinal(): bool
    {
        return $this->final;
    }

    /**
     * @param array<int, self> &$buffer
     * @param int &$opcode
     * @param string &$text
     * @return void
     */
    public static function combineBuffer(array &$buffer, &$opcode, &$text): void
    {
        $opcode = self::OPCODE_TEXT_FRAME;
        $text = '';

        foreach ($buffer as $message) {
            $opcode = $message->getOpcode();
            $text .= $message->getText() ?: '';
        }

        $buffer = [];
    }
}
