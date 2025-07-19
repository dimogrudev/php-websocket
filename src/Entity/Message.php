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
            if ($this->binary) {
                return mb_strlen($this->payload, '8bit');
            }
            return mb_strlen($this->payload, 'UTF-8');
        }
    }

    /**
     * @param string $payload Message payload
     * @param bool $binary Binary message
     * @return void
     */
    public function __construct(
        private(set) string $payload,
        private(set) bool $binary = false
    ) {}

    /**
     * @return string
     */
    public function __toString(): string
    {
        return $this->payload;
    }
}
