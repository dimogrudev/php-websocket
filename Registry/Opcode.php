<?php

namespace Registry;

/**
 * Represents opcode registry
 * @see https://datatracker.ietf.org/doc/html/rfc6455#section-11.8
 */
enum Opcode: int
{
    case CONTINUATION   = 0x0;
    case TEXT           = 0x1;
    case BINARY         = 0x2;
    case CLOSE          = 0x8;
    case PING           = 0x9;
    case PONG           = 0xA;

    /**
     * Determines whether opcode denotes control frame or not
     * @return bool Returns **TRUE** on success or **FALSE** otherwise
     */
    public function isControl(): bool
    {
        if (
            $this == self::CLOSE
            || $this == self::PING || $this == self::PONG
        ) {
            return true;
        }
        return false;
    }
}
