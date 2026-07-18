<?php

namespace WebSocket\Infrastructure\Network;

/**
 * Represents UDP socket listener adapter.
 */
class UDPListener
{
    /** @var resource $stream UDP socket stream. */
    private(set) mixed $stream;

    /**
     * @param string $host UDP socket host to listen on.
     * @param int $port UDP socket port to listen on.
     * @param \Closure $function Callback executed when valid packet is received.
     * @param int $maxPacketLength Maximum buffer length (in bytes) for receiving single UDP datagram.
     */
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly \Closure $function,
        private readonly int $maxPacketLength = 1024
    ) {}

    /**
     * Starts UDP listener.
     * @return void
     */
    public function start(): void
    {
        if (isset($this->stream)) {
            return;
        }

        $stream = @stream_socket_server("udp://{$this->host}:{$this->port}", $errno, $errstr, STREAM_SERVER_BIND);

        if (!is_resource($stream)) {
            throw new \Exception("UDP socket initialization error: (#$errno) {$errstr}");
        }
        if (!@stream_set_blocking($stream, false)) {
            @fclose($stream);
            throw new \Exception("Failed to set non-blocking mode on UDP socket");
        }

        $this->stream = $stream;
    }

    /**
     * Stops UDP listener.
     * @return void
     */
    public function stop(): void
    {
        if (isset($this->stream)) {
            if (is_resource($this->stream)) {
                @fclose($this->stream);
            }
            unset($this->stream);
        }
    }

    /////////////////////////////////

    /**
     * Gets actual socket name assigned by the OS.
     * @return string|null Returns socket name string if UDP listener is initialized or **NULL** otherwise.
     */
    public function getSocketName(): ?string
    {
        if (isset($this->stream) && is_resource($this->stream)) {
            return stream_socket_get_name($this->stream, remote: false);
        }
        return null;
    }

    /**
     * Processes incoming data if the triggered stream matches this listener's socket.
     * @param resource $activeStream Stream resource marked as readable.
     * @return bool Returns **TRUE** if this listener handled the active stream or **FALSE** otherwise.
     */
    public function handleIfActive(mixed $activeStream): bool
    {
        if (!isset($this->stream)) {
            throw new \Exception("UDP listener is not initialized");
        }

        if ($this->stream === $activeStream) {
            $packet = @stream_socket_recvfrom($this->stream, $this->maxPacketLength, address: $peer);

            if ($packet !== false && $peer !== null) {
                $datagram = new UDPDatagram($this, $peer, $packet);
                ($this->function)($datagram);
            }
            return true;
        }
        return false;
    }

    /////////////////////////////////

    /**
     * Sends raw packet response to specified network peer.
     * @param string $peer Remote client endpoint (IP:port).
     * @param string $packet Raw data payload to send.
     * @return int|bool Returns number of bytes sent or **FALSE** on failure.
     */
    public function respond(string $peer, string $packet): int|false
    {
        if (!isset($this->stream)) {
            throw new \Exception("UDP listener is not initialized");
        }

        return @stream_socket_sendto($this->stream, $packet, address: $peer);
    }
}
