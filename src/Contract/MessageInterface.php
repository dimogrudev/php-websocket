<?php

namespace WebSocket\Contract;

use Stringable;

/**
 * Represents message interface for public API.
 */
interface MessageInterface extends Stringable
{
    /** @var int $length Message length. */
    public int $length { get; }
    /** @var string $payload Message payload. */
    public string $payload { get; }
    /** @var bool $isBinary Whether message is binary. */
    public bool $isBinary { get; }
}
