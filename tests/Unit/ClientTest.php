<?php

namespace WebSocket\Test\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WebSocket\Client;
use WebSocket\Domain\Message;
use WebSocket\Domain\Request;
use WebSocket\Infrastructure\Connection;
use WebSocket\Infrastructure\Http\HandshakeParser;
use WebSocket\Infrastructure\Http\Registry\ClientError;
use WebSocket\Protocol\FrameParser;
use WebSocket\Protocol\Registry\CloseCode;
use WebSocket\Protocol\Registry\Opcode;

class ClientTest extends TestCase
{
    public const float BASE_TIME = 1000.0;

    private mixed $stream;
    private Connection $connection;
    /** @var Client&object{mockedTime: float} $client */
    private Client $client;

    protected function setUp(): void
    {
        $this->stream = fopen('php://memory', 'r+');
        $this->connection = new Connection($this->stream, isSecure: false);

        $this->client = new class(
            new HandshakeParser(),
            new FrameParser(maxFrameLength: 1024),
            $this->connection,
            '127.0.0.1'
        ) extends Client {
            public float $mockedTime = ClientTest::BASE_TIME;

            protected function getCurrentTime(): float
            {
                return $this->mockedTime;
            }
        };
    }

    protected function tearDown(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }

    /////////////////////////////////

    private static function createValidRequest(): string
    {
        $key = base64_encode(
            random_bytes(16)
        );
        return "GET /chat HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Key: {$key}\r\n"
            . "Sec-WebSocket-Version: 13\r\n\r\n";
    }

    private static function doHandshake(self $test, mixed $stream, Client $client, Connection $connection): void
    {
        $secKey = base64_encode(
            random_bytes(16)
        );
        $client->performHandshake($secKey);
        $client->push();

        ftruncate($stream, 0);
        rewind($stream);

        $test->assertTrue($client->isHandshakePerformed);
        $test->assertSame(0, $connection->getWriteBufferSize());
    }

    private static function buildTestFrame(bool $isFinal, Opcode $opcode, string $payload): string
    {
        $dataLength = strlen($payload);
        $header = pack('C', ($isFinal ? 0b10000000 : 0b00000000) | $opcode->value);

        if ($dataLength > 65535) {
            $header .= pack('C', 0b10000000 | 127) . pack('J', $dataLength);
        } elseif ($dataLength > 125) {
            $header .= pack('C', 0b10000000 | 126) . pack('n', $dataLength);
        } else {
            $header .= pack('C', 0b10000000 | $dataLength);
        }

        $mask = random_bytes(4);

        if ($dataLength === 0) {
            return $header . $mask;
        }

        $maskedPayload = '';
        for ($i = 0; $i < strlen($payload); $i++) {
            $maskedPayload .= $payload[$i] ^ $mask[$i % 4];
        }

        return $header . $mask . $maskedPayload;
    }

    /////////////////////////////////

    public function testReceiveRequest(): void
    {
        fwrite($this->stream, $this->createValidRequest());
        rewind($this->stream);

        $this->client->pull();
        $request = $this->client->receiveRequest();

        $this->assertInstanceOf(Request::class, $request);
        $this->assertTrue($this->client->isRequestReceived);
        $this->assertSame(0, $this->connection->getReadBufferSize());
    }

    public function testPerformHandshake(): void
    {
        $secKey = base64_encode(
            random_bytes(16)
        );
        $expectedAccept = base64_encode(
            pack('H*', sha1("{$secKey}258EAFA5-E914-47DA-95CA-C5AB0DC85B11"))
        );

        $this->client->performHandshake($secKey);
        $this->client->push();

        rewind($this->stream);
        $responseHeaders = fread($this->stream, 8192);

        $this->assertTrue($this->client->isHandshakePerformed);
        $this->assertStringContainsString("HTTP/1.1 101 Switching Protocols", $responseHeaders);
        $this->assertStringContainsString("Sec-WebSocket-Accept: {$expectedAccept}", $responseHeaders);
    }

    public function testDisconnectWithoutHandshake(): void
    {
        $this->client->disconnect(CloseCode::NORMAL_CLOSURE);
        $this->assertFalse($this->client->isConnected);
    }

    public function testDisconnectWithHandshake(): void
    {
        $this->doHandshake($this, $this->stream, $this->client, $this->connection);

        $this->client->disconnect(CloseCode::NORMAL_CLOSURE);
        $this->client->push();

        rewind($this->stream);
        $responseFrame = fread($this->stream, 8192);

        $this->assertSame(pack('C', 0b10000000 | Opcode::CLOSE->value), $responseFrame[0]);
        $this->assertSame(pack('n', CloseCode::NORMAL_CLOSURE->value), substr($responseFrame, 2, 2));
        $this->assertSame(CloseCode::NORMAL_CLOSURE->getDescription(), substr($responseFrame, 4));
    }

    public function testSendDataMessage(): void
    {
        $testData = 'Hello, world!';

        $this->client->send($testData, isBinary: false);
        $this->client->push();

        rewind($this->stream);
        $responseFrame = fread($this->stream, 8192);

        $this->assertSame(pack('C', 0b10000000 | Opcode::TEXT->value), $responseFrame[0]);
        $this->assertSame(pack('C', strlen($testData)), $responseFrame[1]);
        $this->assertSame($testData, substr($responseFrame, 2));
    }

    /////////////////////////////////

    public static function incomingDataProvider(): array
    {
        $binaryPayload = random_bytes(16);

        return [
            'standard text frame'       => [
                [self::buildTestFrame(isFinal: true, opcode: Opcode::TEXT, payload: 'Hello')],
                function (self $test, ?Message $result, mixed $stream, Client $client, Connection $connection) {
                    $test->assertInstanceOf(Message::class, $result);
                    $test->assertSame('Hello', $result->payload);
                    $test->assertFalse($result->isBinary);
                }
            ],
            'fragmented message'        => [
                [
                    self::buildTestFrame(isFinal: false, opcode: Opcode::TEXT, payload: 'Hello, '),
                    self::buildTestFrame(isFinal: true, opcode: Opcode::CONTINUATION, payload: 'world!')
                ],
                function (self $test, ?Message $result, mixed $stream, Client $client, Connection $connection) {
                    $test->assertInstanceOf(Message::class, $result);
                    $test->assertSame('Hello, world!', $result->payload);
                }
            ],
            'ping frame'                => [
                [self::buildTestFrame(isFinal: true, opcode: Opcode::PING, payload: $binaryPayload)],
                function (self $test, ?Message $result, mixed $stream, Client $client, Connection $connection) use ($binaryPayload) {
                    $test->assertNull($result);

                    ftruncate($stream, 0);
                    rewind($stream);

                    $client->push();

                    rewind($stream);
                    $responseFrame = fread($stream, 8192);

                    $test->assertSame(pack('C', 0b10000000 | Opcode::PONG->value), $responseFrame[0]);
                    $test->assertSame($binaryPayload, substr($responseFrame, 2));
                }
            ],
            'invalid UTF-8'             => [
                [self::buildTestFrame(isFinal: true, opcode: Opcode::TEXT, payload: "\xC3\x28")],
                function (self $test, ?Message $result, mixed $stream, Client $client, Connection $connection) {
                    $test->assertNull($result);

                    $test->assertFalse($client->isConnected);
                    $test->assertTrue($connection->isWriteClosed);
                    $test->assertFalse($connection->isEstablished);
                }
            ],
            'unexpected new text frame' => [
                [
                    self::buildTestFrame(isFinal: false, opcode: Opcode::TEXT, payload: 'Part 1'),
                    self::buildTestFrame(isFinal: true, opcode: Opcode::TEXT, payload: 'Part 2')
                ],
                function (self $test, ?Message $result, mixed $stream, Client $client, Connection $connection) {
                    $test->assertNull($result);

                    $test->assertFalse($client->isConnected);
                    $test->assertTrue($connection->isWriteClosed);
                    $test->assertFalse($connection->isEstablished);
                }
            ]
        ];
    }

    /**
     * @param string[] $frames
     */
    #[DataProvider('incomingDataProvider')]
    public function testIncomingDataProcessing(array $frames, \Closure $assertion): void
    {
        $raw = implode('', $frames);

        fwrite($this->stream, $raw);
        rewind($this->stream);

        $this->client->pull();
        $result = $this->client->handleIncomingData();

        $assertion($this, $result, $this->stream, $this->client, $this->connection);
    }

    public static function timeoutsProvider(): array
    {
        return [
            'handshake: within limits'  => [
                function (self $test, mixed $stream, Client $client, Connection $connection) {},
                Client::TIMEOUT_HANDSHAKE / 1000.0 - 0.1,
                true
            ],
            'handshake: timed out'      => [
                function (self $test, mixed $stream, Client $client, Connection $connection) {},
                Client::TIMEOUT_HANDSHAKE / 1000.0 + 0.1,
                false
            ],
            'ping: within limits'       => [
                function (self $test, mixed $stream, Client $client, Connection $connection) {
                    /** @var Client&object{mockedTime: float} $client */
                    $test->doHandshake($test, $stream, $client, $connection);

                    $client->ping();
                    $client->push();

                    ftruncate($stream, 0);
                    rewind($stream);
                },
                Client::TIMEOUT_PING_RESPONSE / 1000.0 - 0.1,
                true
            ],
            'ping: timed out'           => [
                function (self $test, mixed $stream, Client $client, Connection $connection) {
                    /** @var Client&object{mockedTime: float} $client */
                    $test->doHandshake($test, $stream, $client, $connection);

                    $client->ping();
                    $client->push();

                    ftruncate($stream, 0);
                    rewind($stream);
                },
                Client::TIMEOUT_PING_RESPONSE / 1000.0 + 0.1,
                false
            ],
            'close: within limits'      => [
                function (self $test, mixed $stream, Client $client, Connection $connection) {
                    /** @var Client&object{mockedTime: float} $client */
                    $test->doHandshake($test, $stream, $client, $connection);

                    $client->disconnect(CloseCode::NORMAL_CLOSURE);
                    $client->push();

                    ftruncate($stream, 0);
                    rewind($stream);
                },
                Client::TIMEOUT_CLOSE / 1000.0 - 0.1,
                true
            ],
            'close: timed out'          => [
                function (self $test, mixed $stream, Client $client, Connection $connection) {
                    /** @var Client&object{mockedTime: float} $client */
                    $test->doHandshake($test, $stream, $client, $connection);

                    $client->disconnect(CloseCode::NORMAL_CLOSURE);
                    $client->push();

                    ftruncate($stream, 0);
                    rewind($stream);
                },
                Client::TIMEOUT_CLOSE / 1000.0 + 0.1,
                false
            ],
            'deny: within limits'       => [
                function (self $test, mixed $stream, Client $client, Connection $connection) {
                    fwrite($stream, $test->createValidRequest());
                    rewind($stream);

                    /** @var Client&object{mockedTime: float} $client */
                    $client->pull();
                    $client->receiveRequest();
                    $client->error(ClientError::BAD_REQUEST);

                    ftruncate($stream, 0);
                    rewind($stream);
                },
                Client::TIMEOUT_CLOSE / 1000.0 - 0.1,
                true
            ],
            'deny: timed out'           => [
                function (self $test, mixed $stream, Client $client, Connection $connection) {
                    fwrite($stream, $test->createValidRequest());
                    rewind($stream);

                    /** @var Client&object{mockedTime: float} $client */
                    $client->pull();
                    $client->receiveRequest();
                    $client->error(ClientError::BAD_REQUEST);

                    ftruncate($stream, 0);
                    rewind($stream);
                },
                Client::TIMEOUT_CLOSE / 1000.0 + 0.1,
                false
            ],
        ];
    }

    #[DataProvider('timeoutsProvider')]
    public function testCheckTimeouts(\Closure $setup, float $offsetTime, bool $expectedResult): void
    {
        $setup($this, $this->stream, $this->client, $this->connection);
        $result = $this->client->checkTimeouts(self::BASE_TIME + $offsetTime);

        $this->assertSame($expectedResult, $result);
        $this->assertSame($expectedResult, $this->client->isConnected);
    }
}
