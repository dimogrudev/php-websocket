<?php

namespace WebSocket\Protocol\Exception;

use RuntimeException;

/**
 * Exception thrown when protocol violation occurs.
 * @see https://datatracker.ietf.org/doc/html/rfc6455#section-7.4.1
 */
class ProtocolException extends RuntimeException
{
    /**
     * @param string $message Error message.
     * @param int $code Status code.
     * @param \Throwable|null $previous Previous exception for chaining.
     */
    public function __construct(string $message = "Protocol error", int $code = 1002, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
