<?php

namespace WebSocket\Test\Protocol;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WebSocket\Domain\Message;
use WebSocket\Protocol\Exception\ProtocolException;
use WebSocket\Protocol\MessageBuilder;
use WebSocket\Protocol\Registry\CloseCode;
use WebSocket\Protocol\Registry\Opcode;
use WebSocket\Protocol\Struct\Frame;

class MessageBuilderTest extends TestCase
{
    public static function successfulMessagesProvider(): array
    {
        return [
            'single final text frame'   => [
                [new Frame(isFinal: true, opcode: Opcode::TEXT, payload: 'Hello')],
                'Hello',
                false,
            ],
            'fragmented text message'   => [
                [
                    new Frame(isFinal: false, opcode: Opcode::TEXT, payload: 'Start'),
                    new Frame(isFinal: false, opcode: Opcode::CONTINUATION, payload: ' Middle '),
                    new Frame(isFinal: true, opcode: Opcode::CONTINUATION, payload: 'End')
                ],
                'Start Middle End',
                false,
            ],
            'binary frame'              => [
                [new Frame(isFinal: true, opcode: Opcode::BINARY, payload: "\xC3\x28")],
                "\xC3\x28",
                true,
            ],
        ];
    }

    /**
     * @param Frame[] $frames
     */
    #[DataProvider('successfulMessagesProvider')]
    public function testSuccessfulMessageAssembly(array $frames, string $expectedPayload, bool $isPayloadBinary): void
    {
        $builder = new MessageBuilder(maxFrameBufferSize: 3);
        $message = null;

        foreach ($frames as $frame) {
            $message = $builder->pushFrame($frame);
        }

        $this->assertInstanceOf(Message::class, $message);
        $this->assertSame($expectedPayload, $message->payload);
        $this->assertSame($isPayloadBinary, $message->isBinary);
    }

    public static function protocolExceptionsProvider(): array
    {
        return [
            'unexpected continuation'   => [
                [new Frame(isFinal: true, opcode: Opcode::CONTINUATION, payload: 'Hello')],
                'Unexpected or too many continuation frames',
                CloseCode::PROTOCOL_ERROR->value,
            ],
            'buffer overflow'           => [
                [
                    new Frame(isFinal: false, opcode: Opcode::BINARY, payload: '1'),
                    new Frame(isFinal: false, opcode: Opcode::CONTINUATION, payload: '2'),
                    new Frame(isFinal: false, opcode: Opcode::CONTINUATION, payload: '3'),
                    new Frame(isFinal: false, opcode: Opcode::CONTINUATION, payload: '4')
                ],
                'Unexpected or too many continuation frames',
                CloseCode::PROTOCOL_ERROR->value,
            ],
            'new data frame before previous finished' => [
                [
                    new Frame(isFinal: false, opcode: Opcode::TEXT, payload: 'Part 1'),
                    new Frame(isFinal: true, opcode: Opcode::TEXT, payload: 'Part 2')
                ],
                'New data frame received before previous finished',
                CloseCode::PROTOCOL_ERROR->value,
            ],
            'invalid UTF-8' => [
                [new Frame(isFinal: true, opcode: Opcode::TEXT, payload: "\xC3\x28")],
                'Text payload is not valid UTF-8',
                CloseCode::INVALID_FRAME_PAYLOAD_DATA->value,
            ],
        ];
    }

    /**
     * @param Frame[] $frames
     */
    #[DataProvider('protocolExceptionsProvider')]
    public function testProtocolValidationExceptions(array $frames, string $expectedMessage, int $expectedCode): void
    {
        $builder = new MessageBuilder(maxFrameBufferSize: 3);

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage($expectedMessage);
        $this->expectExceptionCode($expectedCode);

        foreach ($frames as $frame) {
            $builder->pushFrame($frame);
        }
    }

    public function testClearResetsInternalBuffer(): void
    {
        $builder = new MessageBuilder(maxFrameBufferSize: 3);
        $builder->pushFrame(
            new Frame(isFinal: false, opcode: Opcode::TEXT, payload: 'Old data')
        );

        $builder->clear();

        $message = $builder->pushFrame(
            new Frame(isFinal: true, opcode: Opcode::TEXT, payload: 'New data')
        );
        $this->assertSame('New data', $message->payload);
    }
}
