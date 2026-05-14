<?php

namespace WebSocket;

use WebSocket\Contract\ClientInterface;
use WebSocket\Entity\Message;
use WebSocket\Entity\Request;
use WebSocket\Protocol\Frame;
use WebSocket\Registry\Opcode;
use WebSocket\Registry\StatusCode;
use WebSocket\Service\RequestParser;

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

    /** @var int $id Client stream ID. */
    public int $id {
        get => get_resource_id($this->stream);
    }

    /** @var float $connectedAt Connection timestamp. */
    private float $connectedAt;
    /** @var float $pingedAt Ping timestamp. */
    private float $pingedAt;
    /** @var float $closedAt Timestamp when the close frame was sent. */
    private float $closedAt;

    /** @var bool $isConnected Whether connection is established. */
    private(set) bool $isConnected          = true;
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

    /** @var Frame[] $frameBuffer Fragmentation buffer. */
    private array $frameBuffer              = [];

    /** @var string $readBuffer Read buffer. */
    private string $readBuffer              = '';
    /** @var string $writeBuffer Write buffer. */
    private string $writeBuffer             = '';

    /** @var bool $hasDataToWrite Client has data in write buffer. */
    public bool $hasDataToWrite {
        get => $this->writeBuffer !== '';
    }

    /////////////////////////////////

    /**
     * @param RequestParser $requestParser Request parser service.
     * @param resource $stream Client stream.
     * @param string $ipAddr Client IP address.
     * @param bool $isSecure Whether connection is secure.
     * @param int $maxFrameBufferSize Maximum size of fragmentation buffer.
     * @param int $maxChunksPerFrame Maximum amount of data chunks per frame.
     * @param int $maxChunkLength Maximum size (in bytes) of each chunk.
     */
    public function __construct(
        private readonly RequestParser $requestParser,
        public readonly mixed $stream,
        public readonly string $ipAddr,
        private readonly bool $isSecure,
        private readonly int $maxFrameBufferSize = 8,
        private readonly int $maxChunksPerFrame = 8,
        private readonly int $maxChunkLength = 1024
    ) {
        $this->connectedAt = microtime(true);
    }

    /**
     * Disconnects client.
     * @return void
     */
    public function disconnect(): void
    {
        if ($this->isConnected) {
            $this->isConnected = false;

            @stream_socket_shutdown($this->stream, STREAM_SHUT_RDWR);
            @fclose($this->stream);
        }
    }

    /**
     * Pulls data from client's stream to read buffer.
     * @return bool Returns **TRUE** on success or **FALSE** otherwise.
     */
    public function pull(): bool
    {
        if ($this->isConnected && !isset($this->closeFrame)) {
            if ($this->isSecure) {
                while (openssl_error_string() !== false);
            }

            $data = @fread($this->stream, $this->maxChunkLength);

            if ($data === false) {
                if ($this->isSecure && openssl_error_string() === false) {
                    return false;
                }

                $this->disconnect();
                return false;
            } elseif ($data === '' && feof($this->stream)) {
                $this->disconnect();
                return false;
            } elseif ($data === '') {
                return false;
            }

            $this->readBuffer .= $data;

            if (strlen($this->readBuffer) > ($this->maxChunksPerFrame * $this->maxChunkLength)) {
                $this->disconnect();
                return false;
            }

            return true;
        }

        return false;
    }

    /**
     * Pushes data from write buffer to client's stream.
     * @return void
     */
    public function push(): void
    {
        if ($this->isConnected && $this->hasDataToWrite) {
            if ($this->isSecure) {
                while (openssl_error_string() !== false);
            }

            $written = @fwrite($this->stream, $this->writeBuffer);

            if ($written === false) {
                if ($this->isSecure && openssl_error_string() === false) {
                    return;
                }

                $this->disconnect();
                return;
            }

            if ($written > 0) {
                $this->writeBuffer = substr($this->writeBuffer, $written);

                if (isset($this->closeFrame) && !$this->hasDataToWrite) {
                    $this->disconnect();
                }
            }
        }
    }

    /**
     * Extracts raw data from read buffer.
     * @param int|null $length Maximum length of returned string.
     * @param int $offset Data offset in the buffer.
     * @return string Returns raw data string.
     */
    public function readRaw(?int $length = null, int $offset = 0): string
    {
        return substr($this->readBuffer, $offset, $length);
    }

    /**
     * Discards processed data from read buffer.
     * @param int $length Length of raw data to be removed.
     * @return void
     */
    public function discardReadData(int $length): void
    {
        $this->readBuffer = substr($this->readBuffer, $length, null);
    }

    /**
     * Places raw data into write buffer for further sending.
     * @param string $data Raw data string.
     * @return void
     */
    public function sendRaw(string $data): void
    {
        $this->writeBuffer .= $data;
    }

    /**
     * Tries to receive request data from client.
     * @return Request|null Returns request entity or **NULL** on failure.
     */
    public function receiveRequest(): ?Request
    {
        if (!$this->isRequestReceived) {
            $buffer = $this->readRaw();

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

                if ($request = $this->requestParser->parse($requestData)) {
                    $this->discardReadData($dataLength);
                    $this->isRequestReceived = true;
                    return $request;
                } else {
                    $this->error(StatusCode\ClientError::BAD_REQUEST);
                    $this->disconnect();
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

            $this->sendRaw($upgrade);
            return $this->isHandshakePerformed = true;
        }

        return false;
    }

    /**
     * Sends redirection header to the client.
     * @return void
     */
    public function redirect(StatusCode\Redirection $code, string $location): void
    {
        if (!$this->isHandshakePerformed) {
            $header = "HTTP/1.1 {$code->value} {$code->getStatus()}\r\n" .
                "Location: $location\r\n\r\n";
            $this->sendRaw($header);
        }
    }

    /**
     * Sends error header to the client.
     * @return void
     */
    public function error(StatusCode\ClientError $code): void
    {
        if (!$this->isHandshakePerformed) {
            $date = gmdate('D, d M Y H:i:s T');
            $header = "HTTP/1.1 {$code->value} {$code->getStatus()}\r\n" .
                "Date: $date\r\n\r\n";

            $this->sendRaw($header);
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
        while ($frame = Frame::parse($this)) {
            if ($frame->opcode->isControl()) {
                if ($frame->opcode === Opcode::CLOSE) {
                    $this->closedAt = microtime(true);

                    $this->closeFrame = new Frame(true, Opcode::CLOSE, $frame->payload);
                    $this->sendRaw(
                        $this->closeFrame->encode()
                    );
                } elseif ($frame->opcode === Opcode::PING) {
                    $pongFrame = new Frame(true, Opcode::PONG, $frame->payload);
                    $this->sendRaw(
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

                continue;
            }

            if ($frame->opcode === Opcode::CONTINUATION) {
                $bufferSize = count($this->frameBuffer);

                if ($bufferSize === 0 || $bufferSize >= $this->maxFrameBufferSize) {
                    $this->disconnect();
                    return null;
                }

                $this->frameBuffer[] = $frame;
            } else {
                $this->frameBuffer = [$frame];
            }

            if ($frame->isFinal) {
                $opcode = $this->frameBuffer[0]->opcode;
                $isBinary = $opcode === Opcode::BINARY;

                $payloads = [];
                foreach ($this->frameBuffer as $bufferedFrame) {
                    $payloads[] = $bufferedFrame->payload ?? '';
                }

                $fullPayload = implode('', $payloads);
                $this->frameBuffer = [];

                if (!$isBinary && !mb_check_encoding($fullPayload, 'UTF-8')) {
                    $this->disconnect();
                    return null;
                }

                return new Message($fullPayload, $isBinary);
            }
        }

        return null;
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

        $this->sendRaw(
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
        $this->sendRaw(
            $this->pingFrame->encode()
        );
    }

    /**
     * Extracts IP address from stream.
     * @param resource $stream Source stream.
     * @return string|null Returns IP address on success or **NULL** otherwise.
     */
    public static function extractIp($stream): ?string
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
