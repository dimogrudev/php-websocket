# PHP WebSocket Server

A zero-dependency native implementation of WebSocket server in PHP.

Lightweight and minimalistic.

## Features

* Strict adherence to [RFC 6455](https://datatracker.ietf.org/doc/html/rfc6455)
* Automatic HTTP Upgrade handshake compliant with [RFC 9110](https://datatracker.ietf.org/doc/html/rfc9110) and [RFC 9112](https://datatracker.ietf.org/doc/html/rfc9112)
* SSL/TLS encryption support
* Binary and textual data message support, both sending and receiving
* Transparent reassembly of incoming fragmented messages
* Built-in handling for Ping, Pong, and Close control frames
* User-defined non-blocking timers
* Non-blocking I/O

## Requirements

* PHP 8.4 or higher (64-bit)

> [!IMPORTANT]
> If you plan to run websockets on a shared hosting, note that most providers block ports for any third-party usage. A VPS or dedicated server is highly recommended.

## Installation

This library may be installed via [Composer](https://getcomposer.org/):

```bash
composer require dimogrudev/php-websocket
```

## Usage

```php
use WebSocket\Server;
use WebSocket\Contract\ClientInterface;
use WebSocket\Contract\RequestInterface;
use WebSocket\Contract\MessageInterface;

require 'vendor/autoload.php';

// Create an instance of the server class
// 0.0.0.0 is set as host to make the server reachable at all IPv4 addresses
$server = new Server('0.0.0.0', 8443);
// Enable encryption and provide certificate files
$server->encryption(true, 'path/to/cert.crt', 'path/to/cert.key');

// Handle incoming messages and respond to clients
$server->onMessageReceive(function (ClientInterface $client, MessageInterface $message): void {
    if ($message->isBinary) {
        echo "{$client->ipAddr} (#{$client->id}) sends binary message ({$message->length} bytes)\n";

        // Echo the binary message back to the client
        $client->send($message->payload, isBinary: true);
    } else {
        echo "{$client->ipAddr} (#{$client->id}) sends `{$message->payload}`\n";

        // Reply with a text message
        $client->send("Server received your message: {$message->payload}");
    }
});

$server->start();
```

> [!TIP]
> Modern browsers block non-secure WebSocket connections (`ws://`) on secure websites (`https://`). Always use a valid SSL/TLS certificate (e.g. **Let's Encrypt**) for production.

### Timers

```php
// Create a timer to run function repeatedly with a 500 milliseconds interval
// It provides timer ID which may be used for later cancellation
$timerId = $server->setTimer(function (): void {
    // Your logic here
}, 500, true);

// Cancel the timer
$server->clearTimer($timerId);
```

### Callbacks Reference

| Method | Signature | Description |
|:---|:---|:---|
| `onServerStart` | `(): void` | Triggered when the server starts listening. |
| `onServerStop` | `(): void` | Triggered when the server stops. |
| `onHandshake` | `(ClientInterface, RequestInterface): bool` | Triggered when a handshake request is received. Return `true` to accept the connection. |
| `onClientConnect` | `(ClientInterface): void` | Triggered after the handshake is accepted and the connection is fully established. |
| `onClientDisconnect` | `(ClientInterface): void` | Triggered when the connection is closed. |
| `onMessageReceive` | `(ClientInterface, MessageInterface): void` | Triggered when a complete data message is received. |

### Connection Security

Unauthorized connections may be rejected by using the `onHandshake` callback:

```php
$server->onHandshake(function (ClientInterface $client, RequestInterface $request): bool {
    // Reject connection if it's not from your trusted domain
    if ($request->header('origin') !== 'https://example.com') {
        return false;
    }
    return true;
});
```

## License
The MIT License (MIT). For more information, please see [LICENSE](/LICENSE).
