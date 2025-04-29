<?php

namespace Entity;

use Enum\Opcode;

/**
 * Represents client entity
 */
final class Client
{
    const string WEBSOCKET_GUID     = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    const int MAX_HEADERS_LENGTH    = 1024;
    const int MAX_BUFFER_SIZE       = 8;

    const int TIMEOUT_PING_RESPONSE = 10000;
    const int TIMEOUT_HANDSHAKE     = 10000;

    /** @var int $id Client stream ID */
    public int $id {
        get => intval($this->stream);
    }

    /** @var float $connectedAt Time of connection */
    private float $connectedAt;
    /** @var float $pingedAt Time of ping */
    private float $pingedAt;

    /** @var bool $connected Client connected */
    private(set) bool $connected    = true;
    /** @var bool $handshake Handshake performed */
    private(set) bool $handshake    = false;

    /** @var Frame $pingFrame Ping frame sent to client */
    private Frame $pingFrame;

    /** @var Frame[] $buffer Fragmentation buffer */
    private array $buffer           = [];

    /**
     * Class constructor
     * @param resource $stream Client stream
     * @param string $ipAddr Client IP address
     * @return void
     */
    public function __construct(
        private(set) mixed $stream,
        private(set) string $ipAddr
    ) {
        /** @var float $microtime */
        $microtime = microtime(true);

        $this->connectedAt = $microtime;
        $this->pingedAt = $microtime;
    }

    /**
     * Disconnects client
     * @return void
     */
    public function disconnect(): void
    {
        if ($this->connected) {
            $this->connected = false;
            @stream_socket_shutdown($this->stream, STREAM_SHUT_RDWR);
        }
    }

    /**
     * Tries to perform handshake with the client
     * @return bool Returns **TRUE** on success, **FALSE** otherwise
     */
    public function performHandshake(): bool
    {
        $buffer = fread($this->stream, self::MAX_HEADERS_LENGTH);

        if ($buffer) {
            $headers = [];

            foreach ((preg_split('/\r\n/', $buffer) ?: []) as $line) {
                $line = rtrim($line);
                if (preg_match('/\A(\S+): (.*)\z/', $line, $matches)) {
                    $headers[$matches[1]] = $matches[2];
                }
            }

            if (isset($headers['Sec-WebSocket-Key'])) {
                $secKey = $headers['Sec-WebSocket-Key'];
                $secAccept = base64_encode(
                    pack('H*', sha1($secKey . self::WEBSOCKET_GUID))
                );

                $upgrade  = "HTTP/1.1 101 Switching Protocols\r\n" .
                    "Upgrade: websocket\r\n" .
                    "Connection: Upgrade\r\n" .
                    "Sec-WebSocket-Accept: $secAccept\r\n\r\n";

                return $this->handshake = @fwrite($this->stream, $upgrade) !== false;
            }
        }

        return false;
    }

    /**
     * Checks client timeouts
     * @return bool Returns **TRUE** if client is still connected or **FALSE** otherwise
     */
    public function checkTimeouts(): bool
    {
        /** @var float $microtime */
        $microtime = microtime(true);

        if (isset($this->pingFrame)) {
            if (($microtime - $this->pingedAt) * 1000 > self::TIMEOUT_PING_RESPONSE) {
                $this->disconnect();
                return false;
            }
        }
        if (!$this->handshake) {
            if (($microtime - $this->connectedAt) * 1000 > self::TIMEOUT_HANDSHAKE) {
                $this->disconnect();
                return false;
            }
        }

        return true;
    }

    /**
     * Receives text message from client
     * @return string|null Returns text message or **NULL** on control or fragmented frame
     */
    public function receiveMessage(): ?string
    {
        $frame = Frame::receive($this->stream);

        if ($frame->opcode->isControl()) {
            if ($frame->opcode == Opcode::CLOSE) {
                $this->disconnect();
            } else if ($frame->opcode == Opcode::PING) {
                $pongFrame = new Frame($this->stream, true, Opcode::PONG, $frame->payload);
                $pongFrame->send();
            } else if ($frame->opcode == Opcode::PONG) {
                if (
                    isset($this->pingFrame)
                    && $this->pingFrame->payload === $frame->payload
                ) {
                    unset($this->pingFrame);
                }
            }
        } else {
            if ($frame->opcode == Opcode::CONTINUATION) {
                $bufferSize = count($this->buffer);

                if ($bufferSize == 0 || $bufferSize >= self::MAX_BUFFER_SIZE) {
                    $this->disconnect();
                    return null;
                }

                $this->buffer[] = $frame;
            } else {
                $this->buffer = [
                    $frame
                ];
            }

            if ($frame->final) {
                $payload = '';

                foreach ($this->buffer as $bufferedFrame) {
                    $payload .= $bufferedFrame->payload ?? '';
                }
                $this->buffer = [];

                return $payload;
            }
        }

        return null;
    }

    /**
     * Sends text message to client
     * @param string $text Text message
     * @return void
     */
    public function sendMessage(string $text): void
    {
        $textFrame = new Frame($this->stream, true, Opcode::TEXT, $text);
        $textFrame->send();
    }

    /**
     * Sends ping frame to client
     * @return void
     */
    public function ping(): void
    {
        $this->pingedAt = microtime(true);

        $this->pingFrame = new Frame($this->stream, true, Opcode::PING, random_bytes(16));
        $this->pingFrame->send();
    }

    /**
     * Extracts IP address from stream
     * @param resource $stream Source stream
     * @return string|null Returns IP address or **NULL** on failure
     */
    public static function extractIp($stream): ?string
    {
        $socketName = stream_socket_get_name($stream, true);

        if ($socketName) {
            $socketName = preg_replace('/\s+/', '', $socketName);

            if (
                preg_match('/^\[([^\]]+)\](?::(\d+))?$/', $socketName, $matches) ||
                preg_match('/^([0-9.]+)(?::(\d+))?$/', $socketName, $matches)
            ) {
                return $matches[1];
            }
        }

        return null;
    }
}
