<?php

namespace WebSocket\Test\Unit\Protocol;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WebSocket\Infrastructure\Connection;
use WebSocket\Protocol\Exception\ProtocolException;
use WebSocket\Protocol\FrameParser;
use WebSocket\Protocol\Registry\CloseCode;
use WebSocket\Protocol\Registry\Opcode;
use WebSocket\Protocol\Struct\Frame;

class FrameParserTest extends TestCase
{
    private const int MAX_FRAME_LENGTH = 1000;

    private mixed $stream;
    private FrameParser $parser;

    protected function setUp(): void
    {
        $this->stream = fopen('php://memory', 'r+');
        $this->parser = new FrameParser(self::MAX_FRAME_LENGTH);
    }

    protected function tearDown(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }

    /////////////////////////////////

    private function createConnectionWithData(string $bytes): Connection
    {
        fwrite($this->stream, $bytes);
        rewind($this->stream);

        $connection = new Connection($this->stream, isSecure: false);
        $connection->pull();

        return $connection;
    }

    private static function buildTestFrame(int $b1, int $dataLength, string $mask, ?string $payload, bool $isMasked = true): string
    {
        $maskBit = $isMasked ? 0b10000000 : 0b00000000;

        if ($dataLength > 65535) {
            $header = pack('C2', $b1, $maskBit | 127) . pack('J', $dataLength);
        } elseif ($dataLength > 125) {
            $header = pack('C2', $b1, $maskBit | 126) . pack('n', $dataLength);
        } else {
            $header = pack('C2', $b1, $maskBit | $dataLength);
        }

        if (!$isMasked) {
            return $header . ($payload ?? '');
        }

        if ($payload === null || $dataLength === 0) {
            return $header . $mask;
        }

        $maskedPayload = '';
        for ($i = 0; $i < strlen($payload); $i++) {
            $maskedPayload .= $payload[$i] ^ $mask[$i % 4];
        }

        return $header . $mask . $maskedPayload;
    }

    /////////////////////////////////

    public function testParseReturnsNullWhenBufferIsTooSmall(): void
    {
        $connection = $this->createConnectionWithData("\x81");
        $this->assertNull(
            $this->parser->parse($connection)
        );
    }

    public function testParseReturnsNullWhenPayloadIsIncomplete(): void
    {
        $dataLength = 100;

        $header = pack('C2', 0b10000000 | Opcode::TEXT->value, 0b10000000 | $dataLength);
        $mask = "\x11\x22\x33\x44";

        $data = $header . $mask;
        $connection = $this->createConnectionWithData($data);

        $this->assertNull(
            $this->parser->parse($connection)
        );
    }

    public static function successfulFramesProvider(): array
    {
        $mask = "\x11\x22\x33\x44";

        $textPayload = 'Hello';
        $longPayload = str_repeat('A', 130);

        return [
            'standard text frame'       => [
                self::buildTestFrame(0b10000000 | Opcode::TEXT->value, 5, $mask, $textPayload),
                Opcode::TEXT,
                $textPayload,
                true,
            ],
            'fragmented binary frame'   => [
                self::buildTestFrame(0b00000000 | Opcode::BINARY->value, 5, $mask, $textPayload),
                Opcode::BINARY,
                $textPayload,
                false,
            ],
            'empty ping frame'          => [
                self::buildTestFrame(0b10000000 | Opcode::PING->value, 0, $mask, null),
                Opcode::PING,
                null,
                true,
            ],
            'extended payload'          => [
                self::buildTestFrame(0b10000000 | Opcode::BINARY->value, 130, $mask, $longPayload),
                Opcode::BINARY,
                $longPayload,
                true,
            ],
        ];
    }

    #[DataProvider('successfulFramesProvider')]
    public function testSuccessfulFrameParsing(string $bytes, Opcode $expectedOpcode, ?string $expectedPayload, bool $isFrameFinal): void
    {
        $connection = $this->createConnectionWithData($bytes);
        $frame = $this->parser->parse($connection);

        $this->assertInstanceOf(Frame::class, $frame);
        $this->assertSame($expectedOpcode, $frame->opcode);
        $this->assertSame($expectedPayload, $frame->payload);
        $this->assertSame($isFrameFinal, $frame->isFinal);

        $this->assertSame(0, $connection->getReadBufferSize());
    }

    public static function protocolExceptionsProvider(): array
    {
        $mask = "\x11\x22\x33\x44";

        return [
            'unmasked client frame'     => [
                self::buildTestFrame(0b10000000 | Opcode::TEXT->value, 5, $mask, 'Hello', isMasked: false),
                'Client frames must be masked',
                CloseCode::PROTOCOL_ERROR->value,
            ],
            'invalid opcode'            => [
                self::buildTestFrame(0b10000000 | 0x7, 0, $mask, null),
                'Invalid opcode',
                CloseCode::PROTOCOL_ERROR->value,
            ],
            'fragmented control frame'  => [
                self::buildTestFrame(0b00000000 | Opcode::PING->value, 0, $mask, null),
                'Control frames must not be fragmented',
                CloseCode::PROTOCOL_ERROR->value,
            ],
            'control frame too big'     => [
                self::buildTestFrame(0b10000000 | Opcode::PING->value, 130, $mask, null),
                'Only non-control frames can have extended length',
                CloseCode::PROTOCOL_ERROR->value,
            ],
            'payload too big'           => [
                self::buildTestFrame(0b10000000 | Opcode::BINARY->value, 5000, $mask, null),
                'Payload length exceeds maximum allowed limit',
                CloseCode::MESSAGE_TOO_BIG->value,
            ],
        ];
    }

    #[DataProvider('protocolExceptionsProvider')]
    public function testProtocolParsingExceptions(string $bytes, string $expectedMessage, int $expectedCode): void
    {
        $connection = $this->createConnectionWithData($bytes);

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage($expectedMessage);
        $this->expectExceptionCode($expectedCode);

        $this->parser->parse($connection);
    }
}
