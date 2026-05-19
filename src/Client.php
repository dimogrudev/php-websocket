<?php

namespace WebSocket;

use WebSocket\Contract\ClientInterface;
use WebSocket\Domain\Message;
use WebSocket\Domain\Request;
use WebSocket\Infrastructure\Connection;
use WebSocket\Infrastructure\Http\HandshakeParser;
use WebSocket\Infrastructure\Http\Registry\ClientError;
use WebSocket\Infrastructure\Http\Registry\Redirection;
use WebSocket\Protocol\Exception\ProtocolException;
use WebSocket\Protocol\FrameParser;
use WebSocket\Protocol\MessageBuilder;
use WebSocket\Protocol\Registry\Opcode;
use WebSocket\Protocol\Struct\Frame;

/**
 * Represents client entity.
 */
class Client implements ClientInterface
{
    const string WEBSOCKET_GUID             = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    const int TIMEOUT_PING_RESPONSE         = 4000;
    const int TIMEOUT_CLOSE                 = 2000;
    const int TIMEOUT_HANDSHAKE             = 4000;

    /////////////////////////////////

    /** @var MessageBuilder $messageBuilder Message builder component. */
    private readonly MessageBuilder $messageBuilder;

    /** @var int $id Client stream ID. */
    public int $id {
        get => get_resource_id($this->connection->stream);
    }
    /** @var resource $stream Client stream. */
    public mixed $stream {
        get => $this->connection->stream;
    }

    /** @var float $connectedAt Connection timestamp. */
    private float $connectedAt;
    /** @var float $pingedAt Ping timestamp. */
    private float $pingedAt;
    /** @var float $closedAt Timestamp when the close frame was sent. */
    private float $closedAt;

    /** @var bool $isConnected Whether connection is established. */
    public bool $isConnected {
        get => $this->connection->isEstablished;
    }
    /** @var bool $isHandshakePerformed Whether handshake is performed. */
    private(set) bool $isHandshakePerformed = false;

    /** @var bool $isRequestReceived Whether request is received. */
    private(set) bool $isRequestReceived    = false;
    /** @var bool $isRequestAccepted Whether request is accepted by server. */
    private(set) bool $isRequestAccepted    = false;

    /** @var Frame $pingFrame Ping frame sent to client. */
    private Frame $pingFrame;
    /** @var Frame $closeFrame Close frame sent to client. */
    private Frame $closeFrame;

    /** @var bool $hasDataToWrite Client has data in write buffer. */
    public bool $hasDataToWrite {
        get => $this->connection->hasDataToWrite;
    }

    /////////////////////////////////

    /**
     * @param HandshakeParser $handshakeParser Handshake request parser service.
     * @param FrameParser $frameParser Frame parser service.
     * @param Connection $connection Connection stream wrapper.
     * @param string $ipAddr Client IP address.
     * @param int $maxFrameBufferSize Maximum size of fragmentation buffer.
     */
    public function __construct(
        private readonly HandshakeParser $handshakeParser,
        private readonly FrameParser $frameParser,
        private readonly Connection $connection,
        public readonly string $ipAddr,
        int $maxFrameBufferSize = 8
    ) {
        $this->messageBuilder = new MessageBuilder($maxFrameBufferSize);
        $this->connectedAt = microtime(true);
    }

    /**
     * Disconnects client immediately.
     * @return void
     */
    public function disconnect(): void
    {
        $this->connection->close();
    }

    /**
     * Pulls data from client's stream to read buffer.
     * @return bool Returns **TRUE** on success or **FALSE** otherwise.
     */
    public function pull(): bool
    {
        return $this->connection->pull();
    }

    /**
     * Pushes data from write buffer to client's stream.
     * @return void
     */
    public function push(): void
    {
        $this->connection->push();
    }

    /**
     * Tries to receive request data from client.
     * @return Request|null Returns request entity or **NULL** on failure.
     */
    public function receiveRequest(): ?Request
    {
        if (!$this->isRequestReceived) {
            $buffer = $this->connection->readRaw();

            $posCRLF = strpos($buffer, "\r\n\r\n");
            $posLF = strpos($buffer, "\n\n");

            if ($posCRLF !== false && ($posLF === false || $posCRLF < $posLF)) {
                $pos = $posCRLF;
                $len = 4;
            } else {
                $pos = $posLF;
                $len = ($posLF !== false) ? 2 : 0;
            }

            if ($pos !== false) {
                $dataLength = $pos + $len;
                $requestData = substr($buffer, 0, $dataLength);

                if ($request = $this->handshakeParser->parse($requestData)) {
                    $this->connection->discardReadData($dataLength);
                    $this->isRequestReceived = true;
                    return $request;
                } else {
                    $this->error(ClientError::BAD_REQUEST);
                }
            }
        }

        return null;
    }

    /**
     * Confirms request acceptance.
     * @return void
     */
    public function acceptRequest(): void
    {
        if ($this->isRequestReceived) {
            $this->isRequestAccepted = true;
        }
    }

    /**
     * Tries to perform handshake with the client.
     * @param string $secKey Security key.
     * @return bool Returns **TRUE** on success or **FALSE** otherwise.
     */
    public function performHandshake(string $secKey): bool
    {
        if (!$this->isHandshakePerformed) {
            $secAccept = base64_encode(
                pack('H*', sha1($secKey . self::WEBSOCKET_GUID))
            );
            $upgrade = "HTTP/1.1 101 Switching Protocols\r\n" .
                "Upgrade: websocket\r\n" .
                "Connection: Upgrade\r\n" .
                "Sec-WebSocket-Accept: $secAccept\r\n\r\n";

            $this->connection->sendRaw($upgrade);
            return $this->isHandshakePerformed = true;
        }

        return false;
    }

    /**
     * Sends redirection header to the client.
     * @return void
     */
    public function redirect(Redirection $code, string $location): void
    {
        if (!$this->isHandshakePerformed) {
            $header = "HTTP/1.1 {$code->value} {$code->getStatus()}\r\n" .
                "Location: $location\r\n\r\n";
            $this->connection->sendRaw($header);
            $this->connection->finish();
        }
    }

    /**
     * Sends error header to the client.
     * @return void
     */
    public function error(ClientError $code): void
    {
        if (!$this->isHandshakePerformed) {
            $date = gmdate('D, d M Y H:i:s T');
            $header = "HTTP/1.1 {$code->value} {$code->getStatus()}\r\n" .
                "Date: $date\r\n\r\n";

            $this->connection->sendRaw($header);
            $this->connection->finish();
        }
    }

    /**
     * Checks the client's timeouts.
     * @return bool Returns **TRUE** if client is still connected or **FALSE** otherwise.
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
        if (isset($this->closeFrame)) {
            if (($microtime - $this->closedAt) * 1000 > self::TIMEOUT_CLOSE) {
                $this->disconnect();
                return false;
            }
        }
        if (!$this->isHandshakePerformed) {
            if (($microtime - $this->connectedAt) * 1000 > self::TIMEOUT_HANDSHAKE) {
                $this->disconnect();
                return false;
            }
        }

        return true;
    }

    /**
     * Receives data message from the client.
     * @return Message|null Returns data message on success or **NULL** otherwise.
     */
    public function receiveMessage(): ?Message
    {
        try {
            while ($frame = $this->frameParser->parse($this->connection)) {
                if ($frame->opcode->isControl()) {
                    if ($this->handleControlFrame($frame)) {
                        continue;
                    }
                    return null;
                }

                if ($message = $this->messageBuilder->pushFrame($frame)) {
                    return $message;
                }
            }
        } catch (ProtocolException) {
            $this->disconnect();
        }

        return null;
    }

    /**
     * Handles control frames (CLOSE, PING, PONG).
     * @param Frame $frame Control frame.
     * @return bool Whether further frame processing should continue.
     */
    private function handleControlFrame(Frame $frame): bool
    {
        if ($frame->opcode === Opcode::CLOSE) {
            $this->closedAt = microtime(true);
            $this->closeFrame = new Frame(true, Opcode::CLOSE, $frame->payload);

            $this->connection->sendRaw(
                $this->closeFrame->encode()
            );
            $this->connection->finish();

            return false;
        } elseif ($frame->opcode === Opcode::PING) {
            $pongFrame = new Frame(true, Opcode::PONG, $frame->payload);
            $this->connection->sendRaw(
                $pongFrame->encode()
            );
        } elseif ($frame->opcode === Opcode::PONG) {
            if (
                isset($this->pingFrame)
                && $this->pingFrame->payload === $frame->payload
            ) {
                unset($this->pingFrame);
            }
        }

        return true;
    }

    /**
     * Sends data message to the client.
     * @param Message $message Data message.
     * @return void
     */
    public function sendMessage(Message $message): void
    {
        $opcode = $message->isBinary ? Opcode::BINARY : Opcode::TEXT;
        $frame = new Frame(true, $opcode, $message->payload);

        $this->connection->sendRaw(
            $frame->encode()
        );
    }

    /**
     * Sends ping frame to the client.
     * @return void
     */
    public function ping(): void
    {
        $this->pingedAt = microtime(true);

        $this->pingFrame = new Frame(true, Opcode::PING, random_bytes(16));
        $this->connection->sendRaw(
            $this->pingFrame->encode()
        );
    }

    /**
     * Extracts IP address from stream.
     * @param resource $stream Source stream.
     * @return string|null Returns IP address on success or **NULL** otherwise.
     */
    public static function extractIp(mixed $stream): ?string
    {
        $socketName = @stream_socket_get_name($stream, true);

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
