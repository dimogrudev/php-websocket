<?php

namespace WebSocket\Protocol;

use WebSocket\Infrastructure\Connection;
use WebSocket\Protocol\Exception\ProtocolException;
use WebSocket\Protocol\Registry\CloseCode;
use WebSocket\Protocol\Registry\Opcode;
use WebSocket\Protocol\Struct\Frame;
use WebSocket\Protocol\Struct\FrameHeader;

/**
 * Represents frame parser service.
 * @see https://datatracker.ietf.org/doc/html/rfc6455#section-5
 */
class FrameParser
{
    /**
     * @param int $maxFrameLength Maximum allowed size of single frame payload (in bytes).
     */
    public function __construct(
        private readonly int $maxFrameLength,
    ) {}

    /**
     * Attempts to parse single frame from connection read buffer.
     * @param Connection $connection Connection stream wrapper.
     * @return Frame|null Returns parsed frame instance or **NULL** if buffer lacks bytes for complete frame.
     * @throws ProtocolException In case frame format violates protocol.
     * @see https://datatracker.ietf.org/doc/html/rfc6455#section-6.2
     */
    public function parse(Connection $connection): ?Frame
    {
        $header = $this->parseHeader($connection);
        if ($header === null) {
            return null;
        }

        $maskLength = $header->isMasked ? 4 : 0;
        $totalFrameLength = $header->headerLength + $maskLength + $header->dataLength;

        if ($connection->getReadBufferSize() < $totalFrameLength) {
            return null;
        }

        $maskingKey = null;
        if ($header->isMasked) {
            $maskingKey = $connection->readRaw($maskLength, $header->headerLength);
        }

        $payload = null;
        if ($header->dataLength > 0) {
            $payloadOffset = $header->headerLength + $maskLength;
            $payload = $connection->readRaw($header->dataLength, $payloadOffset);

            if ($maskingKey !== null) {
                $payload = $this->unmask($payload, $maskingKey);
            }
        }

        $connection->discardReadData($totalFrameLength);
        return new Frame($header->isFinal, $header->opcode, $payload);
    }

    /**
     * Parses frame header by reading minimal required bytes.
     * @param Connection $connection Connection stream wrapper.
     * @return FrameHeader|null Returns parsed frame header instance or **NULL** if buffer lacks bytes for header metadata.
     * @throws ProtocolException In case header validation rules are violated.
     */
    private function parseHeader(Connection $connection): ?FrameHeader
    {
        $headerLength = 2;

        $bufferSize = $connection->getReadBufferSize();
        if ($bufferSize < $headerLength) {
            return null;
        }

        $header = $connection->readRaw($headerLength);

        $bytes = unpack('C2', $header);
        if (!$bytes) {
            throw new ProtocolException("Failed to unpack frame header metadata", CloseCode::PROTOCOL_ERROR->value);
        }

        // FIN (1 bit)
        $isFinal = (bool)($bytes[1] & 0b10000000);

        try {
            // Opcode (4 bits)
            $opcode = Opcode::from($bytes[1] & 0b00001111);
        } catch (\Error) {
            throw new ProtocolException("Invalid opcode", CloseCode::PROTOCOL_ERROR->value);
        }

        // Mask (1 bit)
        $isMasked = (bool)($bytes[2] & 0b10000000);
        if (!$isMasked) {
            throw new ProtocolException("Client frames must be masked", CloseCode::PROTOCOL_ERROR->value);
        }

        // Payload length (7 bits)
        $dataLength = $bytes[2] & 0b01111111;
        // Whether control frame or not
        $isControl = $opcode->isControl();

        if (!$isFinal && $isControl) {
            throw new ProtocolException("Control frames must not be fragmented", CloseCode::PROTOCOL_ERROR->value);
        }

        if ($dataLength > 125) {
            if ($isControl) {
                throw new ProtocolException("Only non-control frames can have extended length", CloseCode::PROTOCOL_ERROR->value);
            }

            if ($dataLength === 127) {
                $extendedDataLength = 8;
                if ($bufferSize < ($headerLength + $extendedDataLength)) {
                    return null;
                }

                // Extended payload length (64 bits)
                $extendedData = $connection->readRaw($extendedDataLength, $headerLength);
                $headerLength += $extendedDataLength;

                $unpacked = unpack('J', $extendedData);
                if (!$unpacked) {
                    throw new ProtocolException("Failed to unpack 64-bit extended payload length", CloseCode::PROTOCOL_ERROR->value);
                }

                $dataLength = (int)$unpacked[1];
                if ($dataLength < 0 || $dataLength > $this->maxFrameLength) {
                    throw new ProtocolException("Payload length exceeds maximum allowed limit", CloseCode::MESSAGE_TOO_BIG->value);
                }
            } elseif ($dataLength === 126) {
                $extendedDataLength = 2;
                if ($bufferSize < ($headerLength + $extendedDataLength)) {
                    return null;
                }

                // Extended payload length (16 bits)
                $extendedData = $connection->readRaw($extendedDataLength, $headerLength);
                $headerLength += $extendedDataLength;

                $unpacked = unpack('n', $extendedData);
                if (!$unpacked) {
                    throw new ProtocolException("Failed to unpack 16-bit extended payload length", CloseCode::PROTOCOL_ERROR->value);
                }

                $dataLength = (int)$unpacked[1];
            }
        }

        return new FrameHeader($isFinal, $opcode, $isMasked, $dataLength, $headerLength);
    }

    /**
     * Applies XOR masking to the data.
     * @param string $data Raw data.
     * @param string $maskingKey 4-byte masking key.
     * @return string Returns unmasked data.
     */
    private function unmask(string $data, string $maskingKey): string
    {
        $unmasked = '';
        $length = strlen($data);

        for ($i = 0; $i < $length; $i++) {
            $unmasked .= $data[$i] ^ $maskingKey[$i % 4];
        }

        return $unmasked;
    }
}
