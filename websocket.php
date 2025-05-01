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
    echo "\n{$client->ipAddr} (#{$client->id}) connected";
});
$server->on('clientDisconnect', function (Client $client) {
    echo "\n{$client->ipAddr} (#{$client->id}) disconnected";
});
$server->on('messageReceive', function (Client $client, string $message) use ($server) {
    echo "\n{$client->ipAddr} (#{$client->id}) says '{$message}'";

    if ($message == '/stop') {
        $server->stop();
    } else if ($message == '/memusage') {
        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
        $bytes = memory_get_usage();

        $pow = floor(
            log($bytes) / log(1024)
        );

        $client->sendMessage(
            sprintf('%.2f %s', round($bytes / pow(1024, $pow), 2), $units[min($pow, count($units) - 1)])
        );
    } else if ($message == '/online') {
        $client->sendMessage("{$server->online} user(s)");
    } else if ($message == '/uptime') {
        $uptime = $server->uptime;
        $client->sendMessage(
            sprintf('%02d:%02d:%02d', $uptime / 3600, floor($uptime / 60) % 60, $uptime % 60)
        );
    } else {
        $currentId = $client->id;

        foreach ($server->getClients() as $serverClient) {
            if ($serverClient->id != $currentId) {
                $serverClient->sendMessage("{$client->ipAddr} (#{$currentId}): {$message}");
            }
        }
    }
});

$server->start();
