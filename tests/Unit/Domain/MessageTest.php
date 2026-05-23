<?php

namespace WebSocket\Test\Unit\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WebSocket\Domain\Message;

class MessageTest extends TestCase
{
    public static function lengthProvider(): array
    {
        return [
            'multibyte text'    => ['здравейте', false, 9],
            'emoji text'        => ['👋', false, 1],
            'binary emoji'      => ['👋', true, 4],
            'empty string'      => ['', false, 0],
            'standard latin'    => ['hello', false, 5],
            'binary latin'      => ['hello', true, 5],
        ];
    }

    #[DataProvider('lengthProvider')]
    public function testLengthCalculation(string $payload, bool $isBinary, int $expectedLength): void
    {
        $msg = new Message($payload, $isBinary);
        $this->assertEquals($expectedLength, $msg->length);
    }

    public function testToStringReturnsPayload(): void
    {
        $msg = new Message('hello');
        $this->assertEquals('hello', (string)$msg);
    }
}
