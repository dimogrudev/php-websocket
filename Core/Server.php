<?php

namespace Core;

use Entity\Client;

class Server
{
    const CHECK_TIMEOUTS_INTERVAL       = 2000;
    const PING_INTERVAL                 = 20000;

    /** @var string $transport Transport layer protocol */
    private string $transport;
    /** @var string $host Websocket server host */
    private string $host;
    /** @var int $port Websocket server port */
    private int $port;

    /** @var resource|null $stream Server stream */
    private $stream                     = null;
    /** @var resource|null $sslContext Server stream context */
    private $sslContext                 = null;

    /** @var array<int, Client> $clients All current clients */
    private array $clients              = [];
    /** @var bool $running Server is running */
    private bool $running               = false;

    /** @var array<string, \Closure> $callbacks Server callbacks */
    private array $callbacks            = [];

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
                'ssltransport'          => $config['transport'],
            ]]);
        }
    }

    /**
     * Registers server callback
     * @param string $callback Event name
     * @param \Closure $function Callback function
     * @return void
     */
    public function on(string $callback, \Closure $function): void
    {
        $this->callbacks[$callback] = $function;
    }

    /**
     * Triggers server callback
     * @param string $callback Event name
     * @param array $args Callback arguments
     * @return void
     */
    private function triggerCallback(string $callback, array $args): void
    {
        if (isset($this->callbacks[$callback])) {
            $this->callbacks[$callback](...$args);
        }
    }

    /**
     * Server start
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

        $serverStreamId = intval($this->stream);
        $microtime = (float)microtime(true);

        $timeoutsChecked = $microtime;
        $pingSent = $microtime;

        $this->clients = [
            $serverStreamId => new Client($this->stream, $this->host)
        ];
        $this->running = true;

        while ($this->running) {
            $read = Client::getStreams($this->clients);
            $write = null;
            $except = null;

            if (stream_select($read, $write, $except, 1)) {
                foreach ($read as $changingStream) {
                    $streamId = intval($changingStream);

                    if ($streamId == $serverStreamId) {
                        $this->acceptIncomingStream();
                    } else {
                        $ipAddr = Client::extractIp($changingStream);
                        $client = &$this->clients[$streamId];

                        if ($ipAddr) {
                            if (!$client->isHandshakePerformed()) {
                                if ($client->performHandshake()) {
                                    $this->triggerCallback('handshakePerform', [$client]);
                                } else {
                                    $client->disconnect();
                                }
                            } else {
                                $message = $client->receiveMessage();
                                if ($message) {
                                    $this->triggerCallback('messageReceive', [$client, $message]);
                                }
                            }
                        } else {
                            $client->disconnect();
                        }

                        if (!$client->isConnected()) {
                            $this->triggerCallback('clientDisconnect', [$client]);
                        }

                        unset($client);
                    }
                }
            }

            $microtime = (float)microtime(true);

            if (($microtime - $timeoutsChecked) * 1000 >= self::CHECK_TIMEOUTS_INTERVAL) {
                foreach ($this->clients as $streamId => &$client) {
                    if ($streamId != $serverStreamId) {
                        $connected = $client->isConnected();

                        if ($connected) {
                            $connected = $client->checkTimeouts();
                            if (!$connected) {
                                $this->triggerCallback('clientDisconnect', [$client]);
                            }
                        }

                        if (!$connected) {
                            unset($this->clients[$streamId]);
                        }
                    }
                }

                unset($client);
                $timeoutsChecked = $microtime;
            }

            if (($microtime - $pingSent) * 1000 >= self::PING_INTERVAL) {
                foreach ($this->clients as $streamId => &$client) {
                    if ($streamId != $serverStreamId) {
                        if ($client->isConnected() && $client->isHandshakePerformed()) {
                            $client->ping();
                        }
                    }
                }

                unset($client);
                $pingSent = $microtime;
            }
        }

        @stream_socket_shutdown($this->stream, STREAM_SHUT_RDWR);
        $this->triggerCallback('serverStop', []);
    }

    public function stop(): void
    {
        $this->running = false;
    }

    /**
     * @return array<int, Client> All current clients
     */
    public function getClients(): array
    {
        return $this->clients;
    }

    private function init(): bool
    {
        if ($this->sslContext) {
            $this->stream = stream_socket_server("{$this->transport}://{$this->host}:{$this->port}", $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $this->sslContext) ?: null;
        } else {
            $this->stream = stream_socket_server("{$this->transport}://{$this->host}:{$this->port}", $errno, $errstr) ?: null;
        }

        $serverInit = (bool)$this->stream;

        if ($serverInit) {
            $this->triggerCallback('serverStart', []);
        } else {
            $this->triggerCallback('socketError', [$errno, $errstr]);
        }

        return $serverInit;
    }

    private function acceptIncomingStream(): bool
    {
        $incomingStream = @stream_socket_accept($this->stream, 0) ?: null;

        if (is_resource($incomingStream)) {

            $streamId = intval($incomingStream);
            $ipAddr = Client::extractIp($incomingStream);

            if ($ipAddr) {
                $this->clients[$streamId] = new Client($incomingStream, $ipAddr);
                $this->triggerCallback('clientConnect', [$this->clients[$streamId]]);
                return true;
            } else {
                @stream_socket_shutdown($incomingStream, STREAM_SHUT_RDWR);
            }
        }

        return false;
    }
}
