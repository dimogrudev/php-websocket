<?php

namespace WebSocket\Contract;

/**
 * Represents UDP datagram interface for public API.
 */
interface DatagramInterface
{
    /** @var string Remote peer address (IP:port). */
    public string $peer { get; }
    /** @var string Raw data payload of the datagram. */
    public string $payload { get; }

    /**
     * Sends response payload back to the remote peer.
     * @param string $data Raw data to send back.
     * @return void
     */
    public function respond(string $data): void;
}
