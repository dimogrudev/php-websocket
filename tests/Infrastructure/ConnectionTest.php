<?php

namespace WebSocket\Test\Infrastructure;

use PHPUnit\Framework\TestCase;
use WebSocket\Infrastructure\Connection;

class ConnectionTest extends TestCase
{
    private mixed $stream;

    protected function setUp(): void
    {
        $this->stream = fopen('php://memory', 'r+');
    }

    protected function tearDown(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }

    public function testInitialState(): void
    {
        $connection = new Connection($this->stream, isSecure: false);

        $this->assertSame($this->stream, $connection->stream);
        $this->assertTrue($connection->isEstablished);

        $this->assertFalse($connection->isDraining);
        $this->assertFalse($connection->isWriteClosed);
        $this->assertFalse($connection->hasDataToWrite);

        $this->assertSame(0, $connection->getReadBufferSize());
        $this->assertSame(0, $connection->getWriteBufferSize());
    }

    public function testImmediateClose(): void
    {
        $connection = new Connection($this->stream, isSecure: false);
        $connection->close();

        $this->assertFalse($connection->isEstablished);
        $this->assertTrue($connection->isWriteClosed);
        $this->assertFalse($connection->pull());
    }

    public function testSendAndPushData(): void
    {
        $connection = new Connection($this->stream, isSecure: false);
        $testData = 'Hello, world!';

        $connection->sendRaw($testData);
        $this->assertTrue($connection->hasDataToWrite);
        $this->assertSame(strlen($testData), $connection->getWriteBufferSize());

        $connection->push();
        $this->assertFalse($connection->hasDataToWrite);
        $this->assertSame(0, $connection->getWriteBufferSize());

        rewind($this->stream);
        $this->assertSame($testData, fread($this->stream, strlen($testData)));
    }

    public function testPullAndReadData(): void
    {
        $testData = 'Hello, world!';

        fwrite($this->stream, $testData);
        rewind($this->stream);

        $connection = new Connection($this->stream, isSecure: false);

        $this->assertTrue($connection->pull());
        $this->assertSame(strlen($testData), $connection->getReadBufferSize());
        $this->assertSame($testData, $connection->readRaw());

        $this->assertSame(substr($testData, 0, 6), $connection->readRaw(length: 6));
        $connection->discardReadData(6);
        $this->assertSame(substr($testData, 6), $connection->readRaw());
    }

    public function testReadBufferOverflow(): void
    {
        $connection = new Connection($this->stream, isSecure: false, maxChunksPerFrame: 2, maxChunkLength: 4);

        fwrite($this->stream, 'Hello, world!');
        rewind($this->stream);

        $this->assertTrue($connection->pull());

        $this->assertTrue($connection->pull());

        $this->assertFalse($connection->pull());
        $this->assertFalse($connection->isEstablished);
    }

    public function testFinishWithoutDataEntersHalfClose(): void
    {
        $connection = new Connection($this->stream, isSecure: false);

        $connection->finish(forceClose: false);

        $this->assertTrue($connection->isDraining);
        $this->assertTrue($connection->isWriteClosed);
        $this->assertTrue($connection->isEstablished);
    }

    public function testFinishWithDataDrainsBeforeClosing(): void
    {
        $connection = new Connection($this->stream, isSecure: false);

        $connection->sendRaw('Hello, world!');
        $connection->finish(forceClose: true);

        $this->assertTrue($connection->isDraining);
        $this->assertTrue($connection->isEstablished);
        $this->assertTrue($connection->hasDataToWrite);

        $connection->push();

        $this->assertFalse($connection->hasDataToWrite);
        $this->assertFalse($connection->isEstablished);
    }
}
