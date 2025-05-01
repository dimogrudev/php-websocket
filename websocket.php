<?php

use Entity\Client;
use Entity\Request;

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

$server->on('serverError', function (string $errstr): void {
    echo "\n{$errstr}";
});
$server->on('clientConnect', function (Client $client, Request $request): bool {
    if ($request->header('origin')) {
        echo "\n{$client->ipAddr} (#{$client->id}) connected";
        return true;
    }
    return false;
});
$server->on('clientDisconnect', function (Client $client): void {
    echo "\n{$client->ipAddr} (#{$client->id}) disconnected";
});
$server->on('dataReceive', function (Client $client, string $data) use ($server): bool {
    if (mb_check_encoding($data, 'UTF-8')) {
        echo "\n{$client->ipAddr} (#{$client->id}) says '{$data}'";

        if ($data == '/stop') {
            $server->stop();
        } else if ($data == '/memusage') {
            $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
            $bytes = memory_get_usage();

            $pow = floor(
                log($bytes) / log(1024)
            );

            $client->sendTextualData(
                sprintf('%.2f %s', round($bytes / pow(1024, $pow), 2), $units[min($pow, count($units) - 1)])
            );
        } else if ($data == '/online') {
            $client->sendTextualData("{$server->online} user(s)");
        } else if ($data == '/uptime') {
            $uptime = $server->uptime;
            $client->sendTextualData(
                sprintf('%02d:%02d:%02d', $uptime / 3600, floor($uptime / 60) % 60, $uptime % 60)
            );
        } else {
            $currentId = $client->id;

            foreach ($server->getClients() as $serverClient) {
                if ($serverClient->id != $currentId) {
                    $serverClient->sendTextualData("{$client->ipAddr} (#{$currentId}): {$data}");
                }
            }
        }

        return true;
    }

    return false;
});

$server->start();
