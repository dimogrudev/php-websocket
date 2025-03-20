<?php

use Entity\Client;

require __DIR__ . '/autoload.php';

if (Core\Modules\Process::isLocked()) {
    echo "\nProcess locked";
    exit;
}

Core\Modules\Process::lock();
echo "\nRun " . \Core\Modules\Process::getPid();

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

    if ($message == '/stop') {
        $server->stop();
    } else if ($message == '/memusage') {
        $client->sendMessage((memory_get_usage() / 1000) . ' KB');
    } else if ($message == '/online') {
        $client->sendMessage($server->getOnline() . " user(s)");
    } else if ($message == '/uptime') {
        $uptime = $server->getUptime();
        $client->sendMessage(sprintf('%02d:%02d:%02d', $uptime / 3600, floor($uptime / 60) % 60, $uptime % 60));
    } else {
        $client->sendMessageToAll($server->getClients(), "{$ipAddr} (#{$streamId}): {$message}");
    }
});

$server->start();
