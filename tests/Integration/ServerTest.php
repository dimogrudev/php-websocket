<?php

namespace WebSocket\Test\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WebSocket\Client;
use WebSocket\Contract\ClientInterface;
use WebSocket\Contract\MessageInterface;
use WebSocket\Contract\RequestInterface;
use WebSocket\Infrastructure\Connection;
use WebSocket\Infrastructure\Http\HandshakeParser;
use WebSocket\Infrastructure\Http\Registry\ClientError;
use WebSocket\Infrastructure\Http\Registry\Redirection;
use WebSocket\Protocol\FrameParser;
use WebSocket\Protocol\Registry\CloseCode;
use WebSocket\Protocol\Registry\Opcode;
use WebSocket\Server;

class ServerTest extends TestCase
{
    public const float BASE_TIME = 1000.0;

    private static mixed $serverStream;
    private static mixed $clientStream;

    /** @var Server&object{mockedTime: float} $server */
    private Server $server;

    public static function setUpBeforeClass(): void
    {
        self::$serverStream = stream_socket_server("tcp://127.0.0.1:0", $errno, $errstr);
        self::assertIsResource(self::$serverStream, "Failed to create server socket: {$errstr}.");
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$serverStream)) {
            fclose(self::$serverStream);
        }
    }

    protected function setUp(): void
    {
        $this->server = new class('127.0.0.1', 0) extends Server {
            public float $mockedTime = ServerTest::BASE_TIME;

            protected function getCurrentTime(): float
            {
                return $this->mockedTime;
            }

            protected function createClient(Connection $connection, string $ipAddr): Client
            {
                return new class(
                    $this->handshakeParser,
                    $this->frameParser,
                    $connection,
                    $ipAddr,
                    $this
                ) extends Client {
                    /**
                     * @param HandshakeParser $handshakeParser
                     * @param FrameParser $frameParser
                     * @param Connection $connection
                     * @param string $ipAddr
                     * @param Server&object{mockedTime: float} $serverRef
                     */
                    public function __construct(
                        HandshakeParser $handshakeParser,
                        FrameParser $frameParser,
                        Connection $connection,
                        string $ipAddr,
                        private readonly Server $serverRef
                    ) {
                        parent::__construct($handshakeParser, $frameParser, $connection, $ipAddr);
                    }

                    protected function getCurrentTime(): float
                    {
                        return $this->serverRef->mockedTime;
                    }
                };
            }
        };

        $socketName = stream_socket_get_name(self::$serverStream, false);
        $this->assertIsString($socketName);

        self::$clientStream = stream_socket_client("tcp://{$socketName}", $errno, $errstr, 1);
        $this->assertIsResource(self::$clientStream, "Failed to create client socket: {$errstr}.");
    }

    protected function tearDown(): void
    {
        if (is_resource(self::$clientStream)) {
            fclose(self::$clientStream);
        }
        unset($this->server);
    }

    /////////////////////////////////

    private function readFromClientStream(int $bytes = 8192): string
    {
        $read = [self::$clientStream];
        $write = null;
        $except = null;

        if (@stream_select($read, $write, $except, 0, 10000) > 0) {
            return fread(self::$clientStream, $bytes) ?: '';
        }
        return '';
    }

    private function writeToClientStream(string $data, int $timeout = 2000): void
    {
        $length = strlen($data);
        $written = 0;
        $startTime = microtime(true);

        while ($written < $length) {
            if ((microtime(true) - $startTime) * 1000 >= $timeout) {
                $this->fail('Timed out writing data to client stream.');
            }

            $read = null;
            $write = [self::$clientStream];
            $except = null;

            $changedStreams = @stream_select($read, $write, $except, 0, 10000);

            if ($changedStreams === false) {
                $this->fail('Stream select failed during write operation.');
            }
            if ($changedStreams === 0) {
                usleep(100);
                continue;
            }

            $bytesToWrite = substr($data, $written);
            $result = fwrite(self::$clientStream, $bytesToWrite);

            if ($result === false) {
                $this->fail('Failed to write data to client stream (connection lost).');
            }

            $written += $result;
        }
    }

    private static function buildTestFrame(bool $isFinal, Opcode $opcode, string $payload, bool $isMasked = true): string
    {
        $dataLength = strlen($payload);

        $header = pack('C', ($isFinal ? 0b10000000 : 0b00000000) | $opcode->value);
        $maskBit = $isMasked ? 0b10000000 : 0b00000000;

        if ($dataLength > 65535) {
            $header .= pack('C', $maskBit | 127) . pack('J', $dataLength);
        } elseif ($dataLength > 125) {
            $header .= pack('C', $maskBit | 126) . pack('n', $dataLength);
        } else {
            $header .= pack('C', $maskBit | $dataLength);
        }

        if (!$isMasked) {
            return $header . $payload;
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

    private function startServer(): void
    {
        $this->server->setup(self::$serverStream);
    }

    private function awaitState(\Closure $condition, int $timeout = 2000, string $message = 'Timed out waiting for the expected state.'): void
    {
        $startTime = microtime(true);

        while ((microtime(true) - $startTime) * 1000 < $timeout) {
            $this->server->tick();
            if ($condition()) {
                return;
            }

            usleep(100);
        }

        $this->fail($message);
    }

    private function doHandshake(?string $httpRequest = null): string
    {
        if ($httpRequest === null) {
            $key = base64_encode(
                random_bytes(16)
            );
            $httpRequest = "GET / HTTP/1.1\r\n"
                . "Host: localhost\r\n"
                . "Upgrade: websocket\r\n"
                . "Connection: Upgrade\r\n"
                . "Sec-WebSocket-Key: {$key}\r\n"
                . "Sec-WebSocket-Version: 13\r\n\r\n";
        }
        $this->writeToClientStream($httpRequest);

        $serverResponse = '';
        $this->awaitState(
            function () use (&$serverResponse) {
                $serverResponse .= $this->readFromClientStream();
                return str_contains($serverResponse, "\r\n\r\n");
            },
            message: sprintf(
                "Timed out waiting for a valid HTTP handshake response. "
                    . "Received so far:\n\"%s\"",
                $serverResponse
            )
        );

        return $serverResponse;
    }

    private function checkConnectionState(bool $shouldBeConnected): void
    {
        $isConnected = !feof(self::$clientStream);

        $expectedStateStr = $shouldBeConnected ? 'OPEN' : 'CLOSED';
        $actualStateStr = $isConnected ? 'OPEN' : 'CLOSED';

        $this->assertEquals(
            $shouldBeConnected,
            $isConnected,
            "The client stream connection state is incorrect. "
                . "Expected: {$expectedStateStr}, but it is {$actualStateStr}."
        );
    }

    private function receiveFrameFromServer(int $expectedLength): string
    {
        $serverFrame = '';

        $this->awaitState(
            function () use ($expectedLength, &$serverFrame) {
                $serverFrame .= $this->readFromClientStream();
                return strlen($serverFrame) >= $expectedLength;
            },
            message: sprintf(
                "Timed out waiting for a complete WebSocket frame. Expected %d bytes, received %d bytes.\n"
                    . "Hex dump of received data: %s",
                $expectedLength,
                strlen($serverFrame),
                bin2hex($serverFrame)
            )
        );

        return $serverFrame;
    }

    /////////////////////////////////

    public function testValidHandshakeRequestProcessing(): void
    {
        $key = base64_encode(
            random_bytes(16)
        );
        $httpRequest = "GET / HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Key: {$key}\r\n"
            . "Sec-WebSocket-Version: 13\r\n\r\n";

        $isHandshakeCallbackTriggered = false;
        $isConnectionCallbackTriggered = false;

        $this->server->onHandshake(function (ClientInterface $client, RequestInterface $request) use (&$isHandshakeCallbackTriggered): bool {
            $isHandshakeCallbackTriggered = true;
            return true;
        });
        $this->server->onClientConnect(function (ClientInterface $client) use (&$isConnectionCallbackTriggered): void {
            $isConnectionCallbackTriggered = true;
        });

        $this->startServer();
        $serverResponse = $this->doHandshake($httpRequest);

        $this->checkConnectionState(true);
        $this->assertTrue($isHandshakeCallbackTriggered);
        $this->assertTrue($isConnectionCallbackTriggered);

        $expectedAccept = base64_encode(
            pack('H*', sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11'))
        );

        $this->assertSame(1, $this->server->online);
        $this->assertStringStartsWith("HTTP/1.1 101 Switching Protocols", $serverResponse);
        $this->assertStringContainsString("Sec-WebSocket-Accept: {$expectedAccept}", $serverResponse);
    }

    public function testInvalidHandshakeRequestProcessing(): void
    {
        $key = base64_encode(
            random_bytes(16)
        );
        // Missing Upgrade header
        $httpRequest = "GET / HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Key: {$key}\r\n"
            . "Sec-WebSocket-Version: 13\r\n\r\n";

        $isConnectionCallbackTriggered = false;

        $this->server->onClientConnect(function (ClientInterface $client) use (&$isConnectionCallbackTriggered): void {
            $isConnectionCallbackTriggered = true;
        });

        $this->startServer();
        $serverResponse = $this->doHandshake($httpRequest);

        $this->checkConnectionState(false);
        $this->assertFalse($isConnectionCallbackTriggered);

        $this->assertSame(0, $this->server->online);
        $this->assertStringStartsWith("HTTP/1.1 400 Bad Request", $serverResponse);
    }

    public function testHandshakeWithErrorResponse(): void
    {
        $isConnectionCallbackTriggered = false;

        $this->server->onHandshake(function (ClientInterface $client, RequestInterface $request): bool {
            $client->error(ClientError::FORBIDDEN);
            return false;
        });
        $this->server->onClientConnect(function (ClientInterface $client) use (&$isConnectionCallbackTriggered): void {
            $isConnectionCallbackTriggered = true;
        });

        $this->startServer();
        $serverResponse = $this->doHandshake();

        $this->checkConnectionState(false);
        $this->assertFalse($isConnectionCallbackTriggered);

        $this->assertSame(0, $this->server->online);
        $this->assertStringStartsWith("HTTP/1.1 403 Forbidden", $serverResponse);
    }

    public function testHandshakeWithRedirectResponse(): void
    {
        $redirectionLink = 'https://example.com';
        $isConnectionCallbackTriggered = false;

        $this->server->onHandshake(function (ClientInterface $client, RequestInterface $request) use ($redirectionLink): bool {
            $client->redirect(Redirection::MOVED_PERMANENTLY, $redirectionLink);
            return false;
        });
        $this->server->onClientConnect(function (ClientInterface $client) use (&$isConnectionCallbackTriggered): void {
            $isConnectionCallbackTriggered = true;
        });

        $this->startServer();
        $serverResponse = $this->doHandshake();

        $this->checkConnectionState(false);
        $this->assertFalse($isConnectionCallbackTriggered);

        $this->assertSame(0, $this->server->online);
        $this->assertStringStartsWith("HTTP/1.1 301 Moved Permanently", $serverResponse);
        $this->assertStringContainsString("Location: {$redirectionLink}", $serverResponse);
    }

    public static function messageProvider(): array
    {
        $randomBytes = random_bytes(6);

        return [
            'valid text message'        => [
                [self::buildTestFrame(isFinal: true, opcode: Opcode::TEXT, payload: 'hello')],
                true,
                false,
                'hello'
            ],
            'valid binary message'      => [
                [self::buildTestFrame(isFinal: true, opcode: Opcode::BINARY, payload: $randomBytes)],
                true,
                true,
                $randomBytes
            ],
            'valid fragmented message'  => [
                [
                    self::buildTestFrame(isFinal: false, opcode: Opcode::TEXT, payload: 'hello,'),
                    self::buildTestFrame(isFinal: false, opcode: Opcode::CONTINUATION, payload: ' how'),
                    self::buildTestFrame(isFinal: false, opcode: Opcode::CONTINUATION, payload: ' are'),
                    self::buildTestFrame(isFinal: true, opcode: Opcode::CONTINUATION, payload: ' you?'),
                ],
                true,
                false,
                'hello, how are you?'
            ],
            'invalid message'           => [
                [
                    self::buildTestFrame(isFinal: false, opcode: Opcode::TEXT, payload: 'invalid'),
                    self::buildTestFrame(isFinal: false, opcode: Opcode::TEXT, payload: ' message'),
                ],
                false,
                null,
                null
            ],
        ];
    }

    /**
     * @param string[] $clientFrames
     */
    #[DataProvider('messageProvider')]
    public function testMessageProcessing(
        array $clientFrames,
        bool $shouldPass,
        ?bool $isBinaryExpected,
        ?string $expectedPayload
    ): void {
        $serverReplyPayload = 'Hello! Nice to see you!';

        $isMessageReceived = false;
        $isMessageBinary = null;
        $messagePayload = null;

        $this->server->onMessageReceive(function (ClientInterface $client, MessageInterface $message) use (
            $serverReplyPayload,
            &$isMessageReceived,
            &$isMessageBinary,
            &$messagePayload,
        ): void {
            $isMessageReceived = true;
            $isMessageBinary = $message->isBinary;
            $messagePayload = $message->payload;

            $client->send($serverReplyPayload, isBinary: false);
        });

        $this->startServer();
        $this->doHandshake();

        foreach ($clientFrames as $clientFrame) {
            $this->writeToClientStream($clientFrame);
        }

        if ($shouldPass) {
            $this->awaitState(function () use (&$isMessageReceived) {
                return $isMessageReceived;
            });

            $this->assertSame($isBinaryExpected, $isMessageBinary);
            $this->assertEquals($expectedPayload, $messagePayload);

            $serverResponse = $this->receiveFrameFromServer(2 + strlen($serverReplyPayload));

            $expectedTextFrame = $this->buildTestFrame(isFinal: true, opcode: Opcode::TEXT, payload: $serverReplyPayload, isMasked: false);
            $this->assertSame($expectedTextFrame, $serverResponse, 'Server response frame mismatch.');
        } else {
            $serverResponse = '';
            $this->awaitState(
                function () use (&$serverResponse) {
                    $serverResponse .= $this->readFromClientStream();
                    return feof(self::$clientStream);
                },
                message: 'Server did not close connection.'
            );

            $expectedCloseByte = pack('C', 0b10000000 | Opcode::CLOSE->value);
            $this->assertStringStartsWith(
                $expectedCloseByte,
                $serverResponse,
                'Close frame is not presented in the server response.'
            );
        }
    }

    public function testPingFrameProcessing(): void
    {
        $this->startServer();
        $this->doHandshake();

        $pingPayload = random_bytes(16);
        $clientPingFrame = $this->buildTestFrame(isFinal: true, opcode: Opcode::PING, payload: $pingPayload, isMasked: true);

        $this->writeToClientStream($clientPingFrame);

        $serverResponseFrame = $this->receiveFrameFromServer(2 + strlen($pingPayload));
        $expectedPongFrame = $this->buildTestFrame(isFinal: true, opcode: Opcode::PONG, payload: $pingPayload, isMasked: false);

        $this->assertEquals($expectedPongFrame, $serverResponseFrame);
    }

    public function testPongFrameProcessing(): void
    {
        $this->startServer();
        $this->doHandshake();

        $this->assertSame(1, $this->server->online);
        $this->server->mockedTime += Server::INTERVAL_PING / 1000.0 + 0.1;

        $serverPingPayloadLength = 16;
        $serverPingFrame = $this->receiveFrameFromServer(2 + $serverPingPayloadLength);

        $pingPayload = substr($serverPingFrame, 2);

        $expectedPingFrame = $this->buildTestFrame(isFinal: true, opcode: Opcode::PING, payload: $pingPayload, isMasked: false);
        $this->assertEquals($expectedPingFrame, $serverPingFrame);

        $clientPongFrame = $this->buildTestFrame(isFinal: true, opcode: Opcode::PONG, payload: $pingPayload, isMasked: true);
        $this->writeToClientStream($clientPongFrame);

        $this->server->mockedTime += Client::TIMEOUT_PING_RESPONSE / 1000.0 + 0.1;
        $this->server->tick();

        $this->checkConnectionState(true);
    }

    public function testCloseFrameProcessing(): void
    {
        $isDisconnectionCallbackTriggered = false;

        $this->server->onClientDisconnect(function (ClientInterface $client) use (&$isDisconnectionCallbackTriggered): void {
            $isDisconnectionCallbackTriggered = true;
        });

        $this->startServer();
        $this->doHandshake();

        $closePayload = pack('n', CloseCode::NORMAL_CLOSURE->value) . "Goodbye";

        $clientCloseFrame = $this->buildTestFrame(isFinal: true, opcode: Opcode::CLOSE, payload: $closePayload, isMasked: true);
        $this->writeToClientStream($clientCloseFrame);

        $serverResponseFrame = $this->receiveFrameFromServer(2 + strlen($closePayload));
        $this->checkConnectionState(false);
        $this->assertTrue($isDisconnectionCallbackTriggered);

        $expectedCloseFrame = $this->buildTestFrame(isFinal: true, opcode: Opcode::CLOSE, payload: $closePayload, isMasked: false);
        $this->assertEquals($expectedCloseFrame, $serverResponseFrame);
    }

    public function testUnexpectedClientDisconnection(): void
    {
        $isDisconnectionCallbackTriggered = false;

        $this->server->onClientDisconnect(function (ClientInterface $client) use (&$isDisconnectionCallbackTriggered): void {
            $isDisconnectionCallbackTriggered = true;
        });

        $this->startServer();
        $this->doHandshake();

        $this->assertSame(1, $this->server->online);
        fclose(self::$clientStream);

        $this->awaitState(fn() => $this->server->online === 0);
        $this->assertTrue($isDisconnectionCallbackTriggered);
    }

    public function testCustomTimerExecution(): void
    {
        $timerDelay = 400;
        $timerExecutedCount = 0;

        $this->server->setTimer(function () use (&$timerExecutedCount): void {
            $timerExecutedCount++;
        }, $timerDelay, isPeriodic: true);

        $this->startServer();

        for ($i = 0; $i < 3; $i++) {
            $this->server->tick();
            $this->assertSame($i, $timerExecutedCount);

            $this->server->mockedTime += $timerDelay / 1000.0 + 0.1;
        }
    }
}
