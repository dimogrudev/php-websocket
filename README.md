# PHP WebSocket Server
A zero-dependency native implementation of WebSocket server in PHP, made according to [RFC 6455](https://datatracker.ietf.org/doc/html/rfc6455).

Lightweight and minimalistic.

## Features
* SSL/TLS encryption.
* Binary and textual data frames, both sending and receiving.
* Control frames (ping/pong/close).
* Built-in non-blocking timers.
* Non-blocking I/O.

## Requirements
* PHP 8.4 or higher (64-bit)

> [!IMPORTANT]
> If you plan to run websockets on a shared hosting, remember that most shared hosting providers block ports for any third-party usage. You will likely have to use a VPS or a dedicated server.

## Installation

This library may be installed via [Composer](https://getcomposer.org/):

```cmd
composer require dimogrudev/php-websocket
```

## Usage

```php
require 'vendor/autoload.php';

// Create an instance of the server class
// 0.0.0.0 is set as host to make the server reachable at all IPv4 addresses
$server = new WebSocket\Server('0.0.0.0', 8443);
// Enable encryption and provide certificate files
$server->encryption(true, '../example_le1.crt', '../example_le1.key');

// Print the number of users online every 30 seconds
$server->setTimer(function () use ($server): void {
    print "Current online: {$server->online} user(s)\n";
}, 30000, true);
// Handle incoming messages
$server->onMessageReceive(function ($client, $message): void {
    if ($message->isBinary) {
        print "{$client->ipAddr} (#{$client->id}) sends binary message ({$message->length} bytes)\n";
    } else {
        print "{$client->ipAddr} (#{$client->id}) sends `{$message->payload}`\n";
    }
});

$server->start();
```

> [!NOTE]
> Due to their security policies, web browsers won't allow to open a non-secure WebSocket connection (ws:) on a secure website (https:). Using an SSL/TLS certificate (e.g. **Let's Encrypt**, not a self-signed one) is mandatory in this case.

### Timers

```php
// Create a timer to run function repeatedly with a 500 milliseconds interval
// It provides timer ID which may be used for later cancellation
$timerId = $server->setTimer(function (): void {}, 500, true);
// Cancel the timer
$server->clearTimer($timerId);
```

### Callbacks

```php
// Server starts
$server->onServerStart(function (): void {});
// Server stops
$server->onServerStop(function(): void {});
// Client sends handshake request
// Return TRUE to accept the request or FALSE to reject it
$server->onHandshake(function ($client, $request): bool {});
// Client connects
$server->onClientConnect(function ($client): void {});
// Client disconnects
$server->onClientDisconnect(function ($client): void {});
// Client sends message
$server->onMessageReceive(function ($client, $message): void {});
```

## License
The MIT License (MIT). For more information, please see [LICENSE](/LICENSE).
