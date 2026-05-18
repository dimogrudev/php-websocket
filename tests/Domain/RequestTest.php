<?php

namespace WebSocket\Test\Entity;

use PHPUnit\Framework\TestCase;
use WebSocket\Domain\Request;

class RequestTest extends TestCase
{
    public function testRequestStoresDataCorrectly(): void
    {
        $headers = [
            'host'      => 'localhost',
            'upgrade'   => 'websocket'
        ];
        $params  = [
            'foo'       => 'bar'
        ];
        $cookies = [
            'session'   => 'abc'
        ];

        $request = new Request('/chat', $headers, $params, $cookies);

        $this->assertSame('/chat', $request->path);
        $this->assertSame('websocket', $request->header('Upgrade'));
        $this->assertSame('bar', $request->query('foo'));
        $this->assertSame('abc', $request->cookie('session'));
    }

    public function testHeaderIsCaseInsensitive(): void
    {
        $request = new Request('/', ['foo' => 'bar']);
        $this->assertSame('bar', $request->header('FOO'));
    }
}
