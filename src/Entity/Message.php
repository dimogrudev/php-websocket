<?php

namespace WebSocket\Entity;

/**
 * Represents data message entity
 */
class Message
{
    /** @var int $length Message length */
    public int $length {
        get {
            if ($this->isBinary) {
                return strlen($this->payload);
            }
            return mb_strlen($this->payload, 'UTF-8');
        }
    }

    /**
     * @param string $payload Message payload
     * @param bool $isBinary Whether message is binary
     */
    public function __construct(
        public readonly string $payload,
        public readonly bool $isBinary = false
    ) {}

    /**
     * @return string Returns message payload
     */
    public function __toString(): string
    {
        return $this->payload;
    }
}
