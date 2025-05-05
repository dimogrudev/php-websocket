<?php

namespace Core;

use Closure;
use Entity\Client;
use Registry\StatusCode;

/**
 * Represents main server class
 */
final class Server
{
    const int INTERVAL_CHECK_TIMEOUTS   = 2000;
    const int INTERVAL_PING             = 20000;

    /** @var string $transport Transport layer protocol */
    private string $transport;
    /** @var string $host Websocket server host */
    private string $host;
    /** @var int $port Websocket server port */
    private int $port;

    /** @var resource|null $stream Server stream */
    private mixed $stream               = null;
    /** @var resource|null $sslContext Server stream context */
    private mixed $sslContext           = null;

    /** @var bool $running Server is running */
    private(set) bool $running          = false;
    /** @var int $startedAt Time of start */
    private int $startedAt;

    /** @var int $uptime Server uptime */
    public int $uptime {
        get {
            if (isset($this->startedAt)) {
                return time() - $this->startedAt;
            }
            return 0;
        }
    }

    /** @var array<int, Client> $clients All current clients */
    private array $clients              = [];
    /** @var int $online Number of clients online */
    private(set) int $online            = 0;

    /** @var array<string, Closure> $callbacks Server callbacks */
    private array $callbacks            = [];
    /** @var array{function: Closure, interval: int, calledAt: float}[] $timers Server timers */
    private array $timers               = [];

    /**
     * @param array $config Configuration
     * @return void
     */
    public function __construct(array $config)
    {
        $this->transport = $config['transport'];
        $this->host = $config['host'];
        $this->port = $config['port'];

        if ($config['enableSsl']) {
            $this->sslContext = stream_context_create(['ssl' => [
                'local_cert'            => __DIR__ . '/..' . $config['sslCertPath']['crt'],
                'local_pk'              => __DIR__ . '/..' . $config['sslCertPath']['key'],
                'disable_compression'   => true,
                'verify_peer'           => false,
            ]]);
        }

        $this->registerInternalTimers();
    }

    /**
     * Registers server callback
     * @param string $callback Event name
     * @param Closure $function Callback function
     * @return void
     */
    public function on(string $callback, Closure $function): void
    {
        $this->callbacks[$callback] = $function;
    }

    /**
     * Triggers server callback
     * @param string $callback Event name
     * @param array $args Callback arguments
     * @return string|float|int|bool Returns callback result
     */
    private function triggerCallback(string $callback, array $args): string|float|int|bool
    {
        if (isset($this->callbacks[$callback])) {
            $result = $this->callbacks[$callback](...$args);
            return ($result !== null) ? $result : true;
        }
        return true;
    }

    /**
     * Registers server timer
     * @param int $interval Timer interval
     * @param Closure $function Callback function
     * @return void
     */
    public function timer(int $interval, Closure $function): void
    {
        /** @var float $microtime */
        $microtime = microtime(true);

        $this->timers[] = [
            'function'  => $function,
            'interval'  => $interval,
            'calledAt'  => $microtime
        ];
    }

    /**
     * Checks server timers
     * @return void
     */
    private function checkTimers(): void
    {
        /** @var float $microtime */
        $microtime = microtime(true);

        foreach ($this->timers as &$timer) {
            if (($microtime - $timer['calledAt']) * 1000 >= $timer['interval']) {
                $timer['function']();
                $timer['calledAt'] = $microtime;
            }
        }
    }

    /**
     * Registers internal timers
     * @return void
     */
    private function registerInternalTimers(): void
    {
        $this->timer(self::INTERVAL_CHECK_TIMEOUTS, function (): void {
            $serverId = intval($this->stream);

            foreach ($this->clients as $streamId => &$client) {
                if ($streamId != $serverId) {
                    $connected = $client->connected;

                    if ($connected) {
                        $connected = $client->checkTimeouts();
                        if (!$connected && $client->requestAccepted) {
                            $this->online--;
                            $this->triggerCallback('clientDisconnect', [$client]);
                        }
                    }

                    if (!$connected) {
                        unset($this->clients[$streamId]);
                    }
                }
            }
        });

        $this->timer(self::INTERVAL_PING, function (): void {
            $serverId = intval($this->stream);

            foreach ($this->clients as $streamId => &$client) {
                if ($streamId != $serverId) {
                    if ($client->stream && $client->handshakePerformed) {
                        $client->ping();
                    }
                }
            }
        });
    }

    /**
     * Starts server
     * @return void
     */
    public function start(): void
    {
        if ($this->running) {
            $this->triggerCallback('serverError', ['Websocket server is already running']);
            return;
        }
        if (!$this->init() || !$this->stream) {
            return;
        }

        $serverId = intval($this->stream);

        $this->running = true;
        $this->startedAt = time();

        $this->clients = [
            $serverId => new Client($this->stream, $this->host)
        ];
        $this->online = 0;

        while ($this->running) {
            $read = $this->getStreams();
            $write = null;
            $except = null;

            if (stream_select($read, $write, $except, 1)) {
                foreach ($read as $changingStream) {
                    $streamId = intval($changingStream);

                    if ($streamId == $serverId) {
                        $this->acceptIncomingStream();
                    } else if (isset($this->clients[$streamId])) {
                        $ipAddr = Client::extractIp($changingStream);
                        $client = &$this->clients[$streamId];

                        if ($ipAddr) {
                            if (!$client->handshakePerformed) {
                                $request = $client->receiveRequest();

                                if ($request && $this->triggerCallback('clientConnect', [$client, $request])) {
                                    $this->online++;

                                    $client->acceptRequest();
                                    $secKey = $request->header('sec-websocket-key');
                                    if (!$secKey || !$client->performHandshake($secKey)) {
                                        $client->disconnect();
                                    }
                                } else {
                                    $client->error(StatusCode\ClientError::BAD_REQUEST);
                                    $client->disconnect();
                                }
                            } else {
                                $data = $client->receiveData();

                                if (is_string($data)) {
                                    if (!$this->triggerCallback('dataReceive', [$client, $data])) {
                                        $client->disconnect();
                                    }
                                }
                            }
                        } else {
                            $client->disconnect();
                        }

                        if (!$client->connected && $client->requestAccepted) {
                            $this->online--;
                            $this->triggerCallback('clientDisconnect', [$client]);
                        }

                        unset($client);
                    }
                }
            }

            $this->checkTimers();
        }

        @stream_socket_shutdown($this->stream, STREAM_SHUT_RDWR);
        $this->triggerCallback('serverStop', []);
    }

    /**
     * Initializes server
     * @return bool Returns **TRUE** on success, **FALSE** otherwise
     */
    private function init(): bool
    {
        if ($this->sslContext) {
            $this->stream = @stream_socket_server("{$this->transport}://{$this->host}:{$this->port}", $errno, $errstr, context: $this->sslContext) ?: null;
        } else {
            $this->stream = @stream_socket_server("{$this->transport}://{$this->host}:{$this->port}", $errno, $errstr) ?: null;
        }

        $serverInit = (bool)$this->stream;

        if ($serverInit) {
            $this->triggerCallback('serverStart', []);
        } else {
            $this->triggerCallback('socketError', [$errno, $errstr]);
        }

        return $serverInit;
    }

    /**
     * Stops server
     * @return void
     */
    public function stop(): void
    {
        $this->running = false;
    }

    /**
     * Accepts incoming stream
     * @return bool Returns **TRUE** on success, **FALSE** otherwise
     */
    private function acceptIncomingStream(): bool
    {
        $incomingStream = @stream_socket_accept($this->stream, 0) ?: null;

        if (is_resource($incomingStream)) {
            $streamId = intval($incomingStream);
            $ipAddr = Client::extractIp($incomingStream);

            if ($ipAddr) {
                $this->clients[$streamId] = new Client($incomingStream, $ipAddr);
                return true;
            } else {
                @stream_socket_shutdown($incomingStream, STREAM_SHUT_RDWR);
            }
        }

        return false;
    }

    /**
     * Gets all active streams, including server stream
     * @return array<int, resource> Returns active streams
     */
    private function getStreams(): array
    {
        $streams = [];

        foreach ($this->clients as $streamId => $client) {
            if ($client->connected) {
                $streams[$streamId] = $client->stream;
            }
        }

        return $streams;
    }

    /**
     * Gets all clients connected to server
     * @return array<int, Client> Returns connected clients
     */
    public function getClients(): array
    {
        if ($this->stream) {
            $serverId = intval($this->stream);
            $clients = [];

            foreach ($this->clients as $streamId => $client) {
                if (
                    $streamId != $serverId
                    && $client->connected && $client->handshakePerformed
                ) {
                    $clients[] = $client;
                }
            }

            return $clients;
        }
        return [];
    }
}
