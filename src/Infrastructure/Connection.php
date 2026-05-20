<?php

namespace WebSocket\Infrastructure;

/**
 * Represents connection stream wrapper component.
 */
class Connection
{
    /** @var bool $isEstablished Whether connection is established. */
    private(set) bool $isEstablished        = true;
    /** @var bool $isDraining Whether connection is draining buffers.  */
    private(set) bool $isDraining           = false;
    /** @var bool $isWriteClosed Whether outbound stream is shut down. */
    private(set) bool $isWriteClosed        = false;

    /** @var bool $forceCloseAfterDrain Whether to completely close connection after draining buffers. */
    private bool $forceCloseAfterDrain      = false;

    /** @var string $readBuffer Read buffer. */
    private string $readBuffer              = '';
    /** @var string $writeBuffer Write buffer. */
    private string $writeBuffer             = '';

    /** @var bool $hasDataToWrite Whether write buffer has queued data. */
    public bool $hasDataToWrite {
        get => $this->writeBuffer !== '';
    }

    /**
     * @param resource $stream Connection stream.
     * @param bool $isSecure Whether connection is secure.
     * @param int $maxChunksPerFrame Maximum amount of data chunks per frame.
     * @param int $maxChunkLength Maximum size (in bytes) of each chunk.
     */
    public function __construct(
        public readonly mixed $stream,
        private readonly bool $isSecure,
        private readonly int $maxChunksPerFrame = 8,
        private readonly int $maxChunkLength = 1024
    ) {}

    /**
     * Closes connection immediately.
     * @return void
     */
    public function close(): void
    {
        if ($this->isEstablished) {
            $this->isEstablished = false;
            $this->isWriteClosed = true;

            @stream_socket_shutdown($this->stream, STREAM_SHUT_RDWR);
            @fclose($this->stream);
        }
    }

    /**
     * Closes outbound stream.
     * @return void
     */
    private function enterHalfClose(): void
    {
        if (!$this->isWriteClosed && $this->isEstablished) {
            $this->isWriteClosed = true;
            @stream_socket_shutdown($this->stream, STREAM_SHUT_WR);
        }
    }

    /**
     * Drains write buffer then closes connection or shifts to half-close state.
     * @param bool $forceClose Whether to completely close connection after drain.
     * @return void
     */
    public function finish(bool $forceClose = false): void
    {
        if ($this->isWriteClosed) {
            return;
        }

        $this->isDraining = true;
        $this->forceCloseAfterDrain = $forceClose;

        if (!$this->hasDataToWrite) {
            $this->applyCloseStrategy();
        }
    }

    /**
     * Finalizes connection shutdown based on close strategy.
     * @return void
     */
    private function applyCloseStrategy(): void
    {
        if ($this->forceCloseAfterDrain) {
            $this->close();
        } else {
            $this->enterHalfClose();
        }
    }

    /**
     * Pulls data from stream to read buffer.
     * @return bool Returns **TRUE** on success or **FALSE** otherwise.
     */
    public function pull(): bool
    {
        if (!$this->isEstablished) {
            return false;
        }
        if ($this->isSecure) {
            while (openssl_error_string() !== false);
        }

        $data = @fread($this->stream, $this->maxChunkLength);

        if ($data === false) {
            if ($this->isSecure) {
                $sslError = openssl_error_string();

                if ($sslError !== false && preg_match('/WANT_(READ|WRITE)/i', $sslError)) {
                    return false;
                }
            }

            $this->close();
            return false;
        } elseif ($data === '') {
            if (!$this->isSecure || feof($this->stream)) {
                $this->close();
                return false;
            }

            return false;
        }

        $this->readBuffer .= $data;

        if ($this->getReadBufferSize() > ($this->maxChunksPerFrame * $this->maxChunkLength)) {
            $this->close();
            return false;
        }

        return true;
    }

    /**
     * Pushes data from write buffer to stream.
     * @return void
     */
    public function push(): void
    {
        if (!$this->isEstablished || $this->isWriteClosed || !$this->hasDataToWrite) {
            return;
        }
        if ($this->isSecure) {
            while (openssl_error_string() !== false);
        }

        $chunk = $this->isSecure
            ? substr($this->writeBuffer, 0, 8192)
            : $this->writeBuffer;

        $written = @fwrite($this->stream, $chunk);

        if ($written === false) {
            if ($this->isSecure) {
                $sslError = openssl_error_string();

                if ($sslError !== false && preg_match('/WANT_(READ|WRITE)|WRITE_PENDING/i', $sslError)) {
                    return;
                }
            }

            $this->close();
            return;
        }

        if ($written > 0) {
            $this->writeBuffer = substr($this->writeBuffer, $written);

            if ($this->isDraining && !$this->hasDataToWrite) {
                $this->applyCloseStrategy();
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
     * Gets total number of bytes currently stored in read buffer.
     * @return int Returns buffer size in bytes.
     */
    public function getReadBufferSize(): int
    {
        return strlen($this->readBuffer);
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
        if (!$this->isDraining && !$this->isWriteClosed) {
            $this->writeBuffer .= $data;
        }
    }

    /**
     * Gets total number of bytes currently queued in write buffer.
     * @return int Returns buffer size in bytes.
     */
    public function getWriteBufferSize(): int
    {
        return strlen($this->writeBuffer);
    }
}
