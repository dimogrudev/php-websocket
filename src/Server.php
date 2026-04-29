<?php

namespace WebSocket;

use WebSocket\Entity\Client;
use WebSocket\Entity\Request;
use WebSocket\Entity\Message;
use WebSocket\Entity\Timer;
use WebSocket\Registry\Callback;
use WebSocket\Registry\StatusCode;

/**
 * Represents main server class
 */
class Server
{
    const int INTERVAL_CHECK_TIMEOUTS   = 2000;
    const int INTERVAL_PING             = 20000;

    /////////////////////////////////

    /** @var resource $stream Server stream */
    private mixed $stream;
    /** @var resource|null $sslContext Server stream context */
    private mixed $sslContext           = null;

    /** @var bool $running Server is running */
    private(set) bool $running          = false;
    /** @var int $startedAt Start timestamp */
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

    /** @var array<int, \Closure> $callbacks Server callbacks */
    private array $callbacks            = [];
    /** @var Timer[] $timers Server timers */
    private array $timers               = [];

    /////////////////////////////////

    /**
     * @param string $host Websocket server host
     * @param int $port Websocket server port
     * @param int $maxFrameBufferSize Maximum size of fragmentation buffer
     * @param int $maxChunksPerFrame Maximum amount of data chunks per frame
     * @param int $maxChunkLength Maximum size (in bytes) of each chunk
     * @param int $eventLoopTimeout Event loop timeout (in milliseconds)
     * @return void
     */
    public function __construct(
        private string $host,
        private int $port,
        private int $maxFrameBufferSize = 8,
        private int $maxChunksPerFrame = 8,
        private int $maxChunkLength = 1024,
        private int $eventLoopTimeout = 1000
    ) {
        $this->setInternalTimers();
    }

    /**
     * Toggles SSL/TLS encryption
     * @param bool $enabled **TRUE** to enable, **FALSE** to disable
     * @param string|null $crtPath Path to **.crt** certificate file
     * @param string|null $keyPath Path to **.key** certificate file
     * @return void
     */
    public function encryption(bool $enabled, ?string $crtPath = null, ?string $keyPath = null): void
    {
        if ($this->running) {
            throw new \Exception("Websocket server is already running");
        }
        if ($enabled) {
            if (!$crtPath || !$keyPath) {
                throw new \Exception("You must provide SSL/TLS certificate files to enable encryption");
            }

            $this->sslContext = stream_context_create(['ssl' => [
                'local_cert'            => $crtPath,
                'local_pk'              => $keyPath,
                'disable_compression'   => true,
                'verify_peer'           => false,
            ]]);
        } else {
            $this->sslContext = null;
        }
    }

    /**
     * Starts server
     * @return void
     */
    public function start(): void
    {
        if ($this->running) {
            throw new \Exception("Websocket server is already running");
        }
        $this->init();

        $serverId = intval($this->stream);
        $this->clients = [
            $serverId => new Client($this->stream, $this->host)
        ];

        $loopTimeoutMicro = $this->eventLoopTimeout * 1000;

        while ($this->running) {
            $read = $this->getReadableStreams();
            $write = $this->getWritableStreams();
            $except = null;

            if (stream_select($read, $write, $except, 0, $loopTimeoutMicro)) {
                foreach ($read as $changingStream) {
                    $streamId = intval($changingStream);

                    if ($streamId == $serverId) {
                        $this->acceptIncomingStream();
                    } else if (isset($this->clients[$streamId])) {
                        $client = $this->clients[$streamId];

                        if ($client->pull()) {
                            if (!$client->handshakePerformed) {
                                $request = $client->receiveRequest();

                                if ($request) {
                                    if ($this->triggerCallback(Callback::CLIENT_CONNECT, [$client, $request])) {
                                        $this->online++;
                                        $client->acceptRequest();

                                        $secKey = $request->header('sec-websocket-key');
                                        if (!$secKey || !$client->performHandshake($secKey)) {
                                            $client->disconnect();
                                        }
                                    } else {
                                        $client->error(StatusCode\ClientError::FORBIDDEN);
                                        $client->disconnect();
                                    }
                                }
                            } else {
                                while ($message = $client->receiveMessage()) {
                                    $this->triggerCallback(Callback::MESSAGE_RECEIVE, [$client, $message]);
                                }
                            }
                        }

                        if (!$client->connected && $client->requestAccepted) {
                            $this->online--;
                            $this->triggerCallback(Callback::CLIENT_DISCONNECT, [$client]);
                            unset($this->clients[$streamId]);
                        }
                    }
                }

                foreach ($write as $changingStream) {
                    $streamId = intval($changingStream);

                    if (isset($this->clients[$streamId])) {
                        $this->clients[$streamId]->push();
                    }
                }
            }

            $this->checkTimers();
        }

        @stream_socket_shutdown($this->stream, STREAM_SHUT_RDWR);
        unset($this->stream);

        $this->clients = [];
        $this->online = 0;

        $this->triggerCallback(Callback::SERVER_STOP);
    }

    /**
     * Stops server
     * @return void
     */
    public function stop(): void
    {
        $this->running = false;
        unset($this->startedAt);
    }


    /////////////////////////////////

    /**
     * Initializes server
     * @return void
     */
    private function init(): void
    {
        if ($this->sslContext) {
            $stream = @stream_socket_server("tls://{$this->host}:{$this->port}", $errno, $errstr, context: $this->sslContext);
        } else {
            $stream = @stream_socket_server("tcp://{$this->host}:{$this->port}", $errno, $errstr);
        }

        if (!$stream) {
            throw new \Exception("Socket initialization error: (#$errno) {$errstr}");
        }
        $this->stream = $stream;

        $this->running = true;
        $this->startedAt = time();

        $this->triggerCallback(Callback::SERVER_START);
    }

    /**
     * Accepts incoming stream
     * @return bool Returns **TRUE** on success or **FALSE** otherwise
     */
    private function acceptIncomingStream(): bool
    {
        $incomingStream = @stream_socket_accept($this->stream, 0);

        if (is_resource($incomingStream)) {
            $streamId = intval($incomingStream);
            $ipAddr = Client::extractIp($incomingStream);

            if ($ipAddr) {
                $this->clients[$streamId] = new Client($incomingStream, $ipAddr, $this->maxFrameBufferSize, $this->maxChunksPerFrame, $this->maxChunkLength);
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
    private function getReadableStreams(): array
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
     * Gets all streams that have pending data in their write buffers
     * @return array<int, resource> Returns streams ready for a write operation
     */
    private function getWritableStreams(): array
    {
        $streams = [];

        foreach ($this->clients as $streamId => $client) {
            if ($client->connected && $client->hasDataToWrite) {
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
        if (isset($this->stream)) {
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

    ///////////// TIMERS ////////////

    /**
     * Creates server timer
     * @param (\Closure(): void) $function Callback function
     * @param int $delay Timer delay (in milliseconds)
     * @param bool $repeat Run timer repeatedly
     * @return int Returns Timer ID
     */
    public function setTimer(\Closure $function, int $delay, bool $repeat = false): int
    {
        $this->timers[] = new Timer($function, $delay, $repeat);
        return array_key_last($this->timers);
    }

    /**
     * Cancels server timer
     * @param int $timerId Timer ID
     * @return void
     */
    public function clearTimer(int $timerId): void
    {
        if (isset($this->timers[$timerId])) {
            unset($this->timers[$timerId]);
        }
    }

    /**
     * Checks server timers
     * @return void
     */
    private function checkTimers(): void
    {
        /** @var float $microtime */
        $microtime = microtime(true);

        foreach ($this->timers as $timerId => &$timer) {
            if ($timer->checkDelay($microtime)) {
                if (!$timer->enabled) {
                    unset($this->timers[$timerId]);
                }
            }
        }
    }

    /**
     * Create internal timers
     * @return void
     */
    private function setInternalTimers(): void
    {
        $this->setTimer(function (): void {
            $serverId = intval($this->stream);

            foreach ($this->clients as $streamId => &$client) {
                if ($streamId != $serverId) {
                    $connected = $client->connected;

                    if ($connected) {
                        $connected = $client->checkTimeouts();
                        if (!$connected && $client->requestAccepted) {
                            $this->online--;
                            $this->triggerCallback(Callback::CLIENT_DISCONNECT, [$client]);
                        }
                    }

                    if (!$connected) {
                        unset($this->clients[$streamId]);
                    }
                }
            }
        }, self::INTERVAL_CHECK_TIMEOUTS, true);

        $this->setTimer(function (): void {
            $serverId = intval($this->stream);

            foreach ($this->clients as $streamId => &$client) {
                if ($streamId != $serverId) {
                    if ($client->stream && $client->handshakePerformed) {
                        $client->ping();
                    }
                }
            }
        }, self::INTERVAL_PING, true);
    }

    /////////// CALLBACKS ///////////

    /**
     * Registers server callback
     * @param Callback $callback Event
     * @param \Closure|null $function Callback function or **NULL** to delete callback
     * @return void
     */
    private function on(Callback $callback, ?\Closure $function): void
    {
        if ($function) {
            $this->callbacks[$callback->value] = $function;
        } else if (isset($this->callbacks[$callback->value])) {
            unset($this->callbacks[$callback->value]);
        }
    }

    /**
     * Triggers server callback
     * @param Callback $callback Event
     * @param array $args Callback arguments
     * @return string|float|int|bool Returns callback result
     */
    private function triggerCallback(Callback $callback, array $args = []): string|float|int|bool
    {
        if (isset($this->callbacks[$callback->value])) {
            $result = $this->callbacks[$callback->value](...$args);
            return ($result !== null) ? $result : true;
        }
        return true;
    }

    /**
     * Registers server callback triggered on server start
     * @param (\Closure(): void)|null $function Callback function
     * @return void
     */
    public function onServerStart(?\Closure $function): void
    {
        $this->on(Callback::SERVER_START, $function);
    }

    /**
     * Registers server callback triggered on server stop
     * @param (\Closure(): void)|null $function Callback function
     * @return void
     */
    public function onServerStop(?\Closure $function): void
    {
        $this->on(Callback::SERVER_STOP, $function);
    }

    /**
     * Registers server callback triggered on client connect
     * @param (\Closure(Client $client, Request $request): bool)|null $function Callback function
     * @return void
     */
    public function onClientConnect(?\Closure $function): void
    {
        $this->on(Callback::CLIENT_CONNECT, $function);
    }

    /**
     * Registers server callback triggered on client disconnect
     * @param (\Closure(Client $client): void)|null $function Callback function
     * @return void
     */
    public function onClientDisconnect(?\Closure $function): void
    {
        $this->on(Callback::CLIENT_DISCONNECT, $function);
    }

    /**
     * Registers server callback triggered on message receive
     * @param (\Closure(Client $client, Message $message): void)|null $function Callback function
     * @return void
     */
    public function onMessageReceive(?\Closure $function): void
    {
        $this->on(Callback::MESSAGE_RECEIVE, $function);
    }
}
