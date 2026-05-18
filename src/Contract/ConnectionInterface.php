<?php

namespace WebSocket\Contract;

/**
 * Represents interface for managing raw network stream buffers.
 */
interface ConnectionInterface
{
    /**
     * Disconnects client.
     * @return void
     */
    public function disconnect(): void;

    /**
     * Extracts raw data from read buffer.
     * @param int|null $length Maximum length of returned string.
     * @param int $offset Data offset in the buffer.
     * @return string Returns raw data string.
     */
    public function readRaw(?int $length = null, int $offset = 0): string;
    /**
     * Gets total number of bytes currently stored in read buffer.
     * @return int Returns buffer size in bytes.
     */
    public function getReadBufferSize(): int;
    /**
     * Discards processed data from read buffer.
     * @param int $length Length of raw data to be removed.
     * @return void
     */
    public function discardReadData(int $length): void;

    /**
     * Places raw data into write buffer for further sending.
     * @param string $data Raw data string.
     * @return void
     */
    public function sendRaw(string $data): void;
    /**
     * Gets total number of bytes currently queued in write buffer.
     * @return int Returns buffer size in bytes.
     */
    public function getWriteBufferSize(): int;
}
