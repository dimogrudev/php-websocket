<?php

namespace WebSocket\Protocol;

use WebSocket\Domain\Message;
use WebSocket\Protocol\Exception\ProtocolException;
use WebSocket\Protocol\Registry\CloseCode;
use WebSocket\Protocol\Registry\Opcode;
use WebSocket\Protocol\Struct\Frame;

/**
 * Represents data message builder component.
 */
class MessageBuilder
{
    /** @var Frame[] $frameBuffer Fragmentation buffer. */
    private array $frameBuffer  = [];

    /**
     * @param int $maxFrameBufferSize Maximum size of fragmentation buffer.
     */
    public function __construct(
        private readonly int $maxFrameBufferSize = 8,
    ) {}

    /**
     * Reconstructs data message by buffering incoming frames.
     * @param Frame $frame Incoming data or continuation frame.
     * @return Message|null Returns data message instance if final frame received or **NULL** otherwise.
     * @throws ProtocolException In case frame sequencing violates protocol.
     */
    public function pushFrame(Frame $frame): ?Message
    {
        if ($frame->opcode === Opcode::CONTINUATION) {
            $bufferSize = count($this->frameBuffer);

            if ($bufferSize === 0 || $bufferSize >= $this->maxFrameBufferSize) {
                throw new ProtocolException("Unexpected or too many continuation frames", CloseCode::PROTOCOL_ERROR->value);
            }

            $this->frameBuffer[] = $frame;
        } else {
            if ($this->frameBuffer) {
                throw new ProtocolException("New data frame received before previous finished", CloseCode::PROTOCOL_ERROR->value);
            }
            $this->frameBuffer = [$frame];
        }

        if (!$frame->isFinal) {
            return null;
        }
        return $this->buildMessage();
    }

    /**
     * Assembles data message from buffered frames.
     * @return Message Returns assembled binary or textual data message.
     * @throws ProtocolException In case text payload contains invalid UTF-8 sequences.
     */
    private function buildMessage(): Message
    {
        $opcode = $this->frameBuffer[0]->opcode;
        $isBinary = $opcode === Opcode::BINARY;

        $payloads = [];
        foreach ($this->frameBuffer as $bufferedFrame) {
            $payloads[] = $bufferedFrame->payload ?? '';
        }

        $fullPayload = implode('', $payloads);
        $this->clear();

        if (!$isBinary && !mb_check_encoding($fullPayload, 'UTF-8')) {
            throw new ProtocolException("Text payload is not valid UTF-8", CloseCode::INVALID_FRAME_PAYLOAD_DATA->value);
        }

        return new Message($fullPayload, $isBinary);
    }

    /**
     * Clears buffer.
     * @return void
     */
    public function clear(): void
    {
        $this->frameBuffer = [];
    }
}
