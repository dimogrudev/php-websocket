<?php

namespace WebSocket\Test\Protocol;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WebSocket\Domain\Request;
use WebSocket\Protocol\HandshakeParser;

class HandshakeParserTest extends TestCase
{
    public static function handshakeProvider(): array
    {
        $key = base64_encode(
            random_bytes(16)
        );
        $baseHeaders = [
            ['Host', 'localhost'],
            ['Upgrade', 'websocket'],
            ['Connection', 'Upgrade'],
            ['Sec-WebSocket-Key', $key],
            ['Sec-WebSocket-Version', '13'],
        ];

        $replace = fn(array $headers, string $name, string $value) => array_map(
            fn($h) => strcasecmp($h[0], $name) === 0 ? [$h[0], $value] : $h,
            $headers
        );
        $remove = fn(array $headers, string $name) => array_values(
            array_filter(
                $headers,
                fn($h) => strcasecmp($h[0], $name) !== 0
            )
        );

        $cases = [
            // Valid
            'standard'                      => ["\r\n", 'GET', '/chat', $baseHeaders, true, [], [], []],
            'host with port'                => ["\r\n", 'GET', '/chat', $replace($baseHeaders, 'Host', 'localhost:8443'), true, [], [], []],
            'host with IP address'          => ["\r\n", 'GET', '/chat', $replace($baseHeaders, 'Host', '127.0.0.1:8443'), true, [], [], []],
            'with query'                    => ["\r\n", 'GET', '/chat?foo=bar', $baseHeaders, true, [], ['foo' => 'bar'], []],
            'with cookie header'            => ["\r\n", 'GET', '/chat', [...$baseHeaders, ['Cookie', 'a=b; c=d']], true, [], [], ['a' => 'b', 'c' => 'd']],
            'cookie with empty value'       => ["\r\n", 'GET', '/chat', [...$baseHeaders, ['Cookie', 'a=; b=1']], true, [], [], ['a' => '', 'b' => '1']],
            'complex'                       => ["\r\n", 'GET', '/chat?foo=bar', [...$baseHeaders, ['Cookie', 'a=b; c=d']], true, [], ['foo' => 'bar'], ['a' => 'b', 'c' => 'd']],
            'multiple cookie headers'       => ["\r\n", 'GET', '/chat', [...$baseHeaders, ['Cookie', 'a=b'], ['Cookie', 'c=d']], true, [], [], ['a' => 'b', 'c' => 'd']],
            'multiple generic headers'      => ["\r\n", 'GET', '/chat', [...$baseHeaders, ['Custom-Header', 'a'], ['Custom-Header', 'b']], true, ['Custom-Header' => 'a, b'], [], []],
            'empty generic header'          => ["\r\n", 'GET', '/chat', [...$baseHeaders, ['Custom-Header', '']], true, ['Custom-Header' => ''], [], []],
            'extra whitespace'              => ["\r\n", 'GET', '/chat', (
                "Host:    localhost\r\n"            // Extra spaces after colon
                . "Upgrade:websocket\r\n"           // No space after colon
                . "Connection: Upgrade \r\n"        // Trailing space
                . "Sec-WebSocket-Key: {$key}\r\n"
                . "Sec-WebSocket-Version: 13"
            ), true, [], [], []],
            'case-insensitive'              => ["\r\n", 'GET', '/chat', (
                "host: localhost\r\n"
                . "UPGRADE: WEBSOCKET\r\n"
                . "cOnNeCtIoN: uPgRaDe\r\n"
                . "sec-websocket-key: {$key}\r\n"
                . "sec-websocket-version: 13"
            ), true, [], [], []],
            // Invalid
            'with fragment'                 => ["\r\n", 'GET', '/chat#fragment', $baseHeaders, false, [], [], []],
            'not a GET request'             => ["\r\n", 'POST', '/chat', $baseHeaders, false, [], [], []],
            'missing host header'           => ["\r\n", 'GET', '/chat', $remove($baseHeaders, 'Host'), false, [], [], []],
            'empty host header'             => ["\r\n", 'GET', '/chat', $replace($baseHeaders, 'Host', ''), false, [], [], []],
            'multiple host headers'         => ["\r\n", 'GET', '/chat', [...$baseHeaders, ['Host', 'example.com']], false, [], [], []],
            'invalid characters in host'    => ["\r\n", 'GET', '/chat', $replace($baseHeaders, 'Host', 'example_com'), false, [], [], []],
            'invalid version'               => ["\r\n", 'GET', '/chat', $replace($baseHeaders, 'Sec-WebSocket-Version', '12'), false, [], [], []],
            'invalid key length'            => ["\r\n", 'GET', '/chat', $replace($baseHeaders, 'Sec-WebSocket-Key', base64_encode('invalid')), false, [], [], []],
            'space before colon'            => ["\r\n", 'GET', '/chat', (
                "Host: localhost\r\n"
                . "Upgrade: websocket\r\n"
                . "Connection: Upgrade\r\n"
                . "Invalid-Header : value\r\n"
                . "Sec-WebSocket-Key: {$key}\r\n"
                . "Sec-WebSocket-Version: 13"
            ), false, [], [], []],
            'with invalid line ending'      => ["\r\n", 'GET', '/chat', (
                "Host: localhost\r\n"
                . "Upgrade: websocket\r\n"
                . "Connection: Upgrade\r\n"
                . "Invalid-Header: value\r"
                . "Sec-WebSocket-Key: {$key}\r\n"
                . "Sec-WebSocket-Version: 13"
            ), false, [], [], []],
            'null byte in header'           => ["\r\n", 'GET', '/chat', (
                "Host: localhost\r\n"
                . "Upgrade: websocket\r\n"
                . "Connection: Upgrade\r\n"
                . "Bad\0Header: value\r\n"
                . "Sec-WebSocket-Key: {$key}\r\n"
                . "Sec-WebSocket-Version: 13"
            ), false, [], [], []],
            'unfinished header line'        => ["\r\n", 'GET', '/chat', (
                "Host: localhost\r\n"
                . "Upgrade: websocket\r\n"
                . "Connection: Upgrade\r\n"
                . "Incomplete-Header\r\n"
                . "Sec-WebSocket-Key: {$key}\r\n"
                . "Sec-WebSocket-Version: 13"
            ), false, [], [], []],
            // Non-standard
            'LF support'                    => ["\n",   'GET', '/chat', $baseHeaders, true, [], [], []],
        ];

        $dataset = [];

        /** @var array{string, string, string, array|string, bool, array, array, array} $data */
        foreach ($cases as $name => $data) {
            [$eol, $method, $path, $headers, $isValid, $custom, $params, $cookies] = $data;

            if (is_array($headers)) {
                $lines = [];
                foreach ($headers as [$headerName, $headerValue]) {
                    $lines[] = "{$headerName}: {$headerValue}";
                }

                $request = "{$method} {$path} HTTP/1.1{$eol}"
                    . implode($eol, $lines)
                    . "{$eol}{$eol}";
            } else {
                $request = "{$method} {$path} HTTP/1.1{$eol}"
                    . $headers
                    . "{$eol}{$eol}";
            }

            $dataset[$name] = [$request, $isValid, parse_url($path, PHP_URL_PATH), $custom, $params, $cookies];
        }

        return $dataset;
    }

    #[DataProvider('handshakeProvider')]
    public function testParseHandshake(
        string $raw,
        bool $shouldPass,
        string $expectedPath,
        array $expectedHeaders,
        array $expectedParams,
        array $expectedCookies
    ): void {
        $parser = new HandshakeParser;
        $result = $parser->parse($raw);

        if (!$shouldPass) {
            $this->assertNull($result, 'Parser should return null for invalid handshake data.');
            return;
        }

        $this->assertInstanceOf(Request::class, $result);
        $this->assertSame($expectedPath, $result->path);

        $this->assertNotEmpty($result->header('host'));
        $this->assertStringContainsStringIgnoringCase('websocket', $result->header('upgrade'));
        $this->assertStringContainsStringIgnoringCase('upgrade', $result->header('connection'));
        $this->assertSame(16, strlen(
            base64_decode($result->header('sec-websocket-key') ?? '', true) ?: ''
        ));
        $this->assertSame('13', $result->header('sec-websocket-version'));

        foreach ($expectedHeaders as $name => $value) {
            $this->assertSame($value, $result->header($name));
        }
        foreach ($expectedParams as $name => $value) {
            $this->assertSame($value, $result->query($name));
        }
        foreach ($expectedCookies as $name => $value) {
            $this->assertSame($value, $result->cookie($name));
        }
    }
}
