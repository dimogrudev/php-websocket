<?php

namespace WebSocket\Infrastructure\Network;

use WebSocket\Contract\DatagramInterface;

/**
 * Represents UDP datagram DTO.
 */
class UDPDatagram implements DatagramInterface
{
    /**
     * @param UDPListener $listener UDP socket listener adapter.
     * @param string $peer Remote peer address (IP:port).
     * @param string $payload Raw data payload of the datagram.
     */
    public function __construct(
        private readonly UDPListener $listener,
        public readonly string $peer,
        public readonly string $payload
    ) {}

    /**
     * Sends response payload back to the remote peer.
     * @param string $data Raw data to send back.
     * @return void
     */
    public function respond(string $data): void
    {
        $this->listener->respond($this->peer, $data);
    }
}
