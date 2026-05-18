<?php

namespace WebSocket\Protocol;

use WebSocket\Contract\ConnectionInterface;
use WebSocket\Exception\ProtocolException;
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
     * @param ConnectionInterface $client Connection stream wrapper holding read buffer.
     * @return Frame|null Returns parsed frame instance or **NULL** if buffer lacks bytes for complete frame.
     * @throws ProtocolException In case frame format violates protocol.
     * @see https://datatracker.ietf.org/doc/html/rfc6455#section-6.2
     */
    public function parse(ConnectionInterface $client): ?Frame
    {
        $header = $this->parseHeader($client);
        if ($header === null) {
            return null;
        }

        $maskLength = $header->isMasked ? 4 : 0;
        $totalFrameLength = $header->headerLength + $maskLength + $header->dataLength;

        if ($client->getReadBufferSize() < $totalFrameLength) {
            return null;
        }

        $maskingKey = null;
        if ($header->isMasked) {
            $maskingKey = $client->readRaw($maskLength, $header->headerLength);
        }

        $payload = null;
        if ($header->dataLength > 0) {
            $payloadOffset = $header->headerLength + $maskLength;
            $payload = $client->readRaw($header->dataLength, $payloadOffset);

            if ($maskingKey !== null) {
                $payload = $this->unmask($payload, $maskingKey);
            }
        }

        $client->discardReadData($totalFrameLength);
        return new Frame($header->isFinal, $header->opcode, $payload);
    }

    /**
     * Parses frame header by reading minimal required bytes.
     * @param ConnectionInterface $client Connection stream wrapper holding read buffer.
     * @return FrameHeader|null Returns parsed frame header instance or **NULL** if buffer lacks bytes for header metadata.
     * @throws ProtocolException In case header validation rules are violated.
     */
    private function parseHeader(ConnectionInterface $client): ?FrameHeader
    {
        $headerLength = 2;

        $bufferSize = $client->getReadBufferSize();
        if ($bufferSize < $headerLength) {
            return null;
        }

        $header = $client->readRaw($headerLength);

        $bytes = unpack('C2', $header);
        if (!$bytes) {
            throw new ProtocolException("Failed to unpack frame header metadata", 1002);
        }

        // FIN (1 bit)
        $isFinal = (bool)($bytes[1] & 0b10000000);

        try {
            // Opcode (4 bits)
            $opcode = Opcode::from($bytes[1] & 0b00001111);
        } catch (\Error) {
            throw new ProtocolException("Invalid opcode", 1002);
        }

        // Mask (1 bit)
        $isMasked = (bool)($bytes[2] & 0b10000000);
        if (!$isMasked) {
            throw new ProtocolException("Client frames must be masked", 1002);
        }

        // Payload length (7 bits)
        $dataLength = $bytes[2] & 0b01111111;
        // Whether control frame or not
        $isControl = $opcode->isControl();

        if (!$isFinal && $isControl) {
            throw new ProtocolException("Control frames must not be fragmented", 1002);
        }

        if ($dataLength > 125) {
            if ($isControl) {
                throw new ProtocolException("Only non-control frames can have extended length", 1002);
            }

            if ($dataLength === 127) {
                $extendedDataLength = 8;
                if ($bufferSize < ($headerLength + $extendedDataLength)) {
                    return null;
                }

                // Extended payload length (64 bits)
                $extendedData = $client->readRaw($extendedDataLength, $headerLength);
                $headerLength += $extendedDataLength;

                $unpacked = unpack('J', $extendedData);
                if (!$unpacked) {
                    throw new ProtocolException("Failed to unpack 64-bit extended payload length", 1002);
                }

                $dataLength = (int)$unpacked[1];
                if ($dataLength < 0 || $dataLength > $this->maxFrameLength) {
                    throw new ProtocolException("Payload length exceeds maximum allowed limit", 1009);
                }
            } elseif ($dataLength === 126) {
                $extendedDataLength = 2;
                if ($bufferSize < ($headerLength + $extendedDataLength)) {
                    return null;
                }

                // Extended payload length (16 bits)
                $extendedData = $client->readRaw($extendedDataLength, $headerLength);
                $headerLength += $extendedDataLength;

                $unpacked = unpack('n', $extendedData);
                if (!$unpacked) {
                    throw new ProtocolException("Failed to unpack 16-bit extended payload length", 1002);
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
