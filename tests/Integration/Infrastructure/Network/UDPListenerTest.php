<?php

namespace WebSocket\Test\Integration\Infrastructure\Network;

use PHPUnit\Framework\TestCase;
use WebSocket\Infrastructure\Network\UDPListener;

class UDPListenerTest extends TestCase
{
    private UDPListener $udpListener;

    protected function tearDown(): void
    {
        if (isset($this->udpListener)) {
            $this->udpListener->stop();
        }
    }

    /////////////////////////////////

    /** @param (\Closure(UDPListener, string, string): void) $function */
    private function createListener(\Closure $function): void
    {
        $this->udpListener = new UDPListener('127.0.0.1', 0, $function);
        $this->udpListener->start();
    }

    /** @param resource $stream */
    private function waitForPacket(mixed $stream, int $maxAttempts = 50, int $attemptTimeout = 10000): void
    {
        $attempts = 0;

        while (true) {
            $read = [$stream];
            $write = null;
            $except = null;

            if (stream_select($read, $write, $except, 0, $attemptTimeout)) {
                break;
            }
            if (++$attempts > $maxAttempts) {
                $this->fail("Timed out waiting for UDP packet to arrive in the socket buffer.");
            }
        }
    }

    /////////////////////////////////

    public function testProcessIncomingPacketAndRespond(): void
    {
        $replyMessage = 'Packet processed!';

        $receivedPeer = '';
        $receivedPayload = '';

        $this->createListener(function ($listener, $peer, $packet) use (&$receivedPeer, &$receivedPayload, $replyMessage) {
            $receivedPeer = $peer;
            $receivedPayload = $packet;

            $listener->respond($peer, $replyMessage);
        });
        $this->assertIsResource($this->udpListener->stream);

        $socketName = stream_socket_get_name($this->udpListener->stream, false);
        $this->assertIsString($socketName);

        $clientStream = stream_socket_client("udp://{$socketName}", $errno, $errstr);
        $this->assertIsResource($clientStream, "Failed to create client socket: {$errstr}.");

        $testData = 'Hello, world!';
        fwrite($clientStream, $testData);

        $this->waitForPacket($this->udpListener->stream);
        $handled = $this->udpListener->handleIfActive($this->udpListener->stream);

        $this->assertTrue($handled, "Listener should report that it handled its own active stream.");
        $this->assertSame($testData, $receivedPayload, "Captured packet payload does not match the sent message.");
        $this->assertStringStartsWith('127.0.0.1', $receivedPeer, "Sender peer IP should match the local host.");

        $this->waitForPacket($clientStream);

        $clientResponse = fread($clientStream, 1024);
        fclose($clientStream);

        $this->assertSame($replyMessage, $clientResponse, "Client did not receive the expected response from the packet context.");
    }

    public function testIgnoreForeignStreams(): void
    {
        $this->createListener(function ($listener, $peer, $packet) {});

        $foreignStream = fopen('php://memory', 'r+');

        $handled = $this->udpListener->handleIfActive($foreignStream);
        fclose($foreignStream);

        $this->assertFalse($handled, "Listener must ignore streams that do not belong to it.");
    }
}
