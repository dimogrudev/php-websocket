<?php

namespace Entity;

class Client
{
    const MAX_HEADERS_LENGTH    = 4096;
    const WEBSOCKET_GUID        = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    const PING_RESPONSE_TIMEOUT = 10000;
    const HANDSHAKE_TIMEOUT     = 10000;

    /** @var resource $stream Client stream */
    private $stream;
    /** @var string $ipAddr Client IP address */
    private string $ipAddr;
    /** @var float $connectedAt Time of connection */
    private float $connectedAt;
    /** @var float $pingedAt Time of ping */
    private float $pingedAt;

    /** @var bool $connected Client connected */
    private bool $connected     = true;
    /** @var bool $pongExpected Client needs to answer ping message */
    private bool $pongExpected  = false;
    /** @var bool $handshake Handshake performed */
    private bool $handshake     = false;

    /** @var Message[] $buffer Messages buffer */
    private array $buffer       = [];

    /**
     * @param resource $stream Client stream
     * @param string $ipAddr Client IP address
     * @return void
     */
    public function __construct($stream, string $ipAddr)
    {
        $this->stream = $stream;
        $this->ipAddr = $ipAddr;
        $this->connectedAt = (float)microtime(true);
    }

    /**
     * Gets client stream ID
     * @return int Stream ID
     */
    public function getId(): int
    {
        return intval($this->stream);
    }

    /**
     * Gets client stream
     * @return resource Stream
     */
    public function getStream()
    {
        return $this->stream;
    }

    /**
     * Gets client IP address
     * @return string IP address
     */
    public function getIpAddr(): string
    {
        return $this->ipAddr;
    }

    /**
     * Shows if client connected
     * @return bool Client connected
     */
    public function isConnected(): bool
    {
        return $this->connected;
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
     * Shows if handshake performed
     * @return bool Handshake performed
     */
    public function isHandshakePerformed(): bool
    {
        return $this->handshake;
    }

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
                $secAccept = base64_encode(pack('H*', sha1($secKey . self::WEBSOCKET_GUID)));

                $upgrade  = "HTTP/1.1 101 Switching Protocols\r\n" .
                    "Upgrade: websocket\r\n" .
                    "Connection: Upgrade\r\n" .
                    "Sec-WebSocket-Accept: $secAccept\r\n\r\n";

                return $this->handshake = @fwrite($this->stream, $upgrade) !== false;
            }
        }

        return false;
    }

    public function checkTimeouts(): bool
    {
        $microtime = (float)microtime(true);

        if ($this->pongExpected) {
            if (($microtime - $this->pingedAt) * 1000 > self::PING_RESPONSE_TIMEOUT) {
                $this->disconnect();
                return false;
            }
        }
        if (!$this->handshake) {
            if (($microtime - $this->connectedAt) * 1000 > self::HANDSHAKE_TIMEOUT) {
                $this->disconnect();
                return false;
            }
        }

        return true;
    }

    /**
     * Receives text message from client
     * @return string|null
     */
    public function receiveMessage(): ?string
    {
        $message = Message::receive($this->stream);

        $this->buffer[] = $message;

        if ($message->isFinal()) {
            $msgOpcode = Message::OPCODE_TEXT;
            $msgText = '';

            foreach ($this->buffer as $message) {
                $msgOpcode = $message->getOpcode();
                $msgText .= $message->getPayload() ?? '';
            }

            $this->buffer = [];

            if ($msgOpcode == Message::OPCODE_CONNECTION_CLOSE) {
                $this->disconnect();
            } else if ($msgOpcode == Message::OPCODE_PING) {
                $pongMsg = new Message($this->stream, Message::OPCODE_PONG);
                $pongMsg->send();
            } else if ($msgOpcode == Message::OPCODE_PONG) {
                $this->pongExpected = false;
            } else {
                return $msgText;
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
        $message = new Message($this->stream, Message::OPCODE_TEXT, $text);
        $message->send();
    }

    /**
     * Sends ping frame to client
     * @return void
     */
    public function ping(): void
    {
        if (!$this->pongExpected) {
            $this->pingedAt = (float)microtime(true);
            $this->pongExpected = true;

            $pingMsg = new Message($this->stream, Message::OPCODE_PING);
            $pingMsg->send();
        }
    }

    /**
     * @param array<int, self> $clients
     * @param string $text
     * @return void
     */
    public function sendMessageToAll(array $clients, string $text): void
    {
        $currentId = $this->getId();

        foreach ($clients as $client) {
            if ($client->isConnected() && $client->getId() != $currentId) {
                $client->sendMessage($text);
            }
        }
    }

    /**
     * Exctracts IP address from client stream
     * @param resource $stream Client stream
     * @return string|null IP address
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

    /**
     * @param array<int, self> $clients
     * @return array<int, resource>
     */
    public static function getStreams(array $clients): array
    {
        $streams = [];

        foreach ($clients as $streamId => $client) {
            if ($client->isConnected()) {
                $streams[$streamId] = $client->getStream();
            }
        }

        return $streams;
    }
}
