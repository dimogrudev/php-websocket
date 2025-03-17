<?php

use Entity\Client;

require __DIR__ . '/autoload.php';

set_time_limit(0);

$config = require(__DIR__ . '/config.php');
$server = new Core\Server($config);

$server->on('serverError', function (string $errstr) {
    echo "\n{$errstr}";
});
$server->on('clientConnect', function (Client $client) {
    echo "\n" . $client->getIpAddr() . " (#" . $client->getId() . ") connected";
});
$server->on('clientDisconnect', function (Client $client) {
    echo "\n" . $client->getIpAddr() . " (#" . $client->getId() . ") disconnected";
});
$server->on('handshakePerform', function (Client $client) {
    echo "\nHandshake with #" . $client->getId() . " successfully performed";
});
$server->on('messageReceive', function (Client $client, string $message) use ($server) {
    $ipAddr = $client->getIpAddr();
    $streamId = $client->getId();

    echo "\n{$ipAddr} (#{$streamId}) says '{$message}'";
	$client->sendMessageToAll($server->getClients(), "{$ipAddr} (#{$streamId}): {$message}");
});

$server->start();
