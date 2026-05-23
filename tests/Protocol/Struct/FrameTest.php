<?php

namespace WebSocket\Test\Protocol\Struct;

use PHPUnit\Framework\TestCase;
use WebSocket\Protocol\Exception\ProtocolException;
use WebSocket\Protocol\Registry\CloseCode;
use WebSocket\Protocol\Registry\Opcode;
use WebSocket\Protocol\Struct\Frame;

class FrameTest extends TestCase
{
    public function testValidFrame(): void
    {
        $payload = 'Hello';
        $frame = new Frame(isFinal: true, opcode: Opcode::TEXT, payload: $payload);

        $this->assertTrue($frame->isFinal);
        $this->assertSame(Opcode::TEXT, $frame->opcode);
        $this->assertSame($payload, $frame->payload);
    }

    public function testControlFrameMustBeFinal(): void
    {
        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('Control frames must not be fragmented');
        $this->expectExceptionCode(CloseCode::PROTOCOL_ERROR->value);

        new Frame(isFinal: false, opcode: Opcode::PING);
    }

    public function testControlFramePayloadLengthLimit(): void
    {
        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('Only non-control frames can have extended length');
        $this->expectExceptionCode(CloseCode::PROTOCOL_ERROR->value);

        $invalidPayload = str_repeat('A', 126);
        new Frame(isFinal: true, opcode: Opcode::PING, payload: $invalidPayload);
    }

    public function testEncodeSmallPayload(): void
    {
        $payload = 'Hello';

        $frame = new Frame(isFinal: true, opcode: Opcode::TEXT, payload: $payload);
        $encoded = $frame->encode();

        $this->assertSame(pack('C', 0b10000000 | 0x1), $encoded[0]);
        $this->assertSame(pack('C', strlen($payload)), $encoded[1]);
        $this->assertSame($payload, substr($encoded, 2));

        $this->assertSame($encoded, (string)$frame);
    }

    public function testEncodeMediumPayload(): void
    {
        $payload = str_repeat('A', 200);

        $frame = new Frame(isFinal: true, opcode: Opcode::BINARY, payload: $payload);
        $encoded = $frame->encode();

        $this->assertSame(pack('C', 0b10000000 | 0x82), $encoded[0]);
        $this->assertSame(pack('C', 126), $encoded[1]);
        $this->assertSame(pack('n', 200), substr($encoded, 2, 2));
    }

    public function testEncodeLargePayload(): void
    {
        $payload = str_repeat('A', 70000);

        $frame = new Frame(isFinal: true, opcode: Opcode::BINARY, payload: $payload);
        $encoded = $frame->encode();

        $this->assertSame(pack('C', 0b10000000 | 0x82), $encoded[0]);
        $this->assertSame(pack('C', 127), $encoded[1]);
        $this->assertSame(pack('J', 70000), substr($encoded, 2, 8));
    }
}
