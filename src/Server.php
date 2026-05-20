<?php

namespace WebSocket;

use WebSocket\Contract\ClientInterface;
use WebSocket\Contract\MessageInterface;
use WebSocket\Contract\RequestInterface;
use WebSocket\Domain\Registry\Event;
use WebSocket\Infrastructure\Connection;
use WebSocket\Infrastructure\Http\HandshakeParser;
use WebSocket\Infrastructure\Http\Registry\ClientError;
use WebSocket\Infrastructure\Timer;
use WebSocket\Protocol\FrameParser;
use WebSocket\Protocol\Registry\CloseCode;

/**
 * Represents main server class.
 */
class Server
{
    const int INTERVAL_CHECK_TIMEOUTS   = 2000;
    const int INTERVAL_PING             = 20000;

    /////////////////////////////////

    /** @var HandshakeParser $handshakeParser Handshake request parser service. */
    private readonly HandshakeParser $handshakeParser;
    /** @var FrameParser $frameParser Frame parser service. */
    private readonly FrameParser $frameParser;

    /** @var resource $stream Server stream. */
    private mixed $stream;
    /** @var resource|null $sslContext Server stream context. */
    private mixed $sslContext           = null;

    /** @var bool $isRunning Whether server is running. */
    private(set) bool $isRunning        = false;
    /** @var bool $isShuttingDown Whether server is in process of shutting down. */
    private(set) bool $isShuttingDown   = false;
    /** @var int $startedAt Start timestamp. */
    private int $startedAt;

    /** @var int $uptime Server uptime. */
    public int $uptime {
        get {
            if (isset($this->startedAt)) {
                return time() - $this->startedAt;
            }
            return 0;
        }
    }

    /** @var array<int, Client> $clients All current clients. */
    private array $clients              = [];
    /** @var int $online Number of clients online. */
    private(set) int $online            = 0;

    /** @var array<int, \Closure> $callbacks Server callbacks. */
    private array $callbacks            = [];
    /** @var Timer[] $timers Server timers. */
    private array $timers               = [];

    /////////////////////////////////

    /**
     * @param string $host Websocket server host.
     * @param int $port Websocket server port.
     * @param int $maxFrameBufferSize Maximum size of fragmentation buffer.
     * @param int $maxChunksPerFrame Maximum amount of data chunks per frame.
     * @param int $maxChunkLength Maximum size (in bytes) of each chunk.
     * @param int $eventLoopTimeout Event loop timeout (in milliseconds).
     */
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly int $maxFrameBufferSize = 8,
        private readonly int $maxChunksPerFrame = 8,
        private readonly int $maxChunkLength = 1024,
        private readonly int $eventLoopTimeout = 50
    ) {
        $this->handshakeParser = new HandshakeParser;
        $this->frameParser = new FrameParser($maxChunksPerFrame * $maxChunkLength);

        $this->setInternalTimers();
    }

    /**
     * Toggles SSL/TLS encryption.
     * @param bool $isEnabled **TRUE** to enable, **FALSE** to disable.
     * @param string|null $crtPath Path to **.crt** certificate file.
     * @param string|null $keyPath Path to **.key** certificate file.
     * @return void
     */
    public function encryption(bool $isEnabled, ?string $crtPath = null, ?string $keyPath = null): void
    {
        if (isset($this->stream)) {
            throw new \Exception("Websocket server is already initialized");
        }
        if ($isEnabled) {
            if ($crtPath === null || $keyPath === null) {
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
     * Starts server.
     * @return void
     */
    public function start(): void
    {
        if (!isset($this->stream)) {
            $serverStream = $this->createServerStream();
            $this->setup($serverStream);
        }

        $this->isRunning = true;
        $this->startedAt = time();

        while ($this->isRunning) {
            $this->tick();
        }

        $this->shutdown();
    }

    /**
     * Initializes server using provided stream resource.
     * @param resource $stream Stream resource.
     * @return void
     */
    public function setup(mixed $stream): void
    {
        if (isset($this->stream)) {
            throw new \Exception("Websocket server is already initialized");
        }
        if (!is_resource($stream)) {
            throw new \InvalidArgumentException("Invalid stream resource provided");
        }
        if (!@stream_set_blocking($stream, false)) {
            @fclose($stream);
            throw new \Exception("Failed to set non-blocking mode on server socket");
        }

        $this->stream = $stream;
        $this->triggerCallback(Event::SERVER_START);
    }

    /**
     * Stops server.
     * @return void
     */
    public function stop(): void
    {
        $this->isRunning = false;
    }

    /**
     * Resets server internal state and parameters to their default values.
     * @return void
     */
    public function reset(): void
    {
        unset($this->stream);

        $this->clients = [];
        $this->online = 0;

        $this->triggerCallback(Event::SERVER_STOP);
    }

    /**
     * Executes single iteration of server's event loop.
     * @return void
     */
    public function tick(): void
    {
        $loopTimeoutMicro = $this->eventLoopTimeout * 1000;

        $read = $this->getReadableStreams();
        $write = $this->getWritableStreams();
        $except = null;

        if (@stream_select($read, $write, $except, 0, $loopTimeoutMicro)) {
            foreach ($read as $changingStream) {
                if ($changingStream === $this->stream) {
                    $this->acceptIncomingStream();
                } else {
                    $streamId = get_resource_id($changingStream);

                    if (isset($this->clients[$streamId])) {
                        $client = $this->clients[$streamId];

                        if ($client->pull()) {
                            $this->processClient($client);
                        }
                        if (!$client->isConnected) {
                            $this->removeClient($client);
                        }
                    }
                }
            }

            foreach ($write as $changingStream) {
                $streamId = get_resource_id($changingStream);

                if (isset($this->clients[$streamId])) {
                    $client = $this->clients[$streamId];
                    $client->push();

                    if (!$client->isConnected) {
                        $this->removeClient($client);
                    }
                }
            }
        }

        $this->checkTimers();
    }

    /////////////////////////////////

    /**
     * Creates server socket stream.
     * @return resource Returns stream resource.
     */
    private function createServerStream(): mixed
    {
        if ($this->sslContext !== null) {
            $stream = @stream_socket_server("tls://{$this->host}:{$this->port}", $errno, $errstr, context: $this->sslContext);
        } else {
            $stream = @stream_socket_server("tcp://{$this->host}:{$this->port}", $errno, $errstr);
        }

        if (!is_resource($stream)) {
            throw new \Exception("Socket initialization error: (#$errno) {$errstr}");
        }
        return $stream;
    }

    /**
     * Shuts down server.
     * @return void
     */
    private function shutdown(): void
    {
        $this->isShuttingDown = true;

        $this->closeConnections();
        $this->reset();

        $this->isShuttingDown = false;
        unset($this->startedAt);
    }

    /**
     * Closes all active client connections and shuts down server socket stream.
     * @return void
     */
    private function closeConnections(): void
    {
        foreach ($this->clients as $client) {
            $client->disconnect(CloseCode::GOING_AWAY, forceClose: true);
        }

        while ($this->clients) {
            $this->tick();
        }

        @stream_socket_shutdown($this->stream, STREAM_SHUT_RDWR);
        @fclose($this->stream);
    }

    /**
     * Accepts incoming stream.
     * @return bool Returns **TRUE** on success or **FALSE** otherwise.
     */
    private function acceptIncomingStream(): bool
    {
        $incomingStream = @stream_socket_accept($this->stream, 0);

        if (is_resource($incomingStream)) {
            if (!@stream_set_blocking($incomingStream, false)) {
                @stream_socket_shutdown($incomingStream, STREAM_SHUT_RDWR);
                @fclose($incomingStream);
                return false;
            }

            $streamId = get_resource_id($incomingStream);
            $ipAddr = Client::extractIp($incomingStream);

            if ($ipAddr === null) {
                @stream_socket_shutdown($incomingStream, STREAM_SHUT_RDWR);
                @fclose($incomingStream);
                return false;
            }

            $connection = new Connection(
                $incomingStream,
                $this->sslContext !== null,
                $this->maxChunksPerFrame,
                $this->maxChunkLength
            );

            $this->clients[$streamId] = new Client(
                $this->handshakeParser,
                $this->frameParser,
                $connection,
                $ipAddr,
                $this->maxFrameBufferSize
            );
            return true;
        }

        return false;
    }

    /**
     * Processes client's state and incoming data.
     * @param Client $client Client instance.
     * @return void
     */
    private function processClient(Client $client): void
    {
        if (!$client->isHandshakePerformed) {
            if ($request = $client->receiveRequest()) {
                if ($this->triggerCallback(Event::HANDSHAKE, [$client, $request])) {
                    $this->online++;
                    $client->acceptRequest();

                    $this->triggerCallback(Event::CLIENT_CONNECT, [$client]);

                    $secKey = $request->header('sec-websocket-key');
                    if ($secKey === null) {
                        $client->error(ClientError::BAD_REQUEST);
                        return;
                    }

                    $client->performHandshake($secKey);
                } else {
                    $client->error(ClientError::FORBIDDEN);
                }
            }
        } else {
            while ($message = $client->handleIncomingData()) {
                $this->triggerCallback(Event::MESSAGE_RECEIVE, [$client, $message]);
            }
        }
    }

    /**
     * Removes disconnected client.
     * @param Client $client Client instance.
     * @return void
     */
    private function removeClient(Client $client): void
    {
        if ($client->isRequestAccepted) {
            $this->online--;
            $this->triggerCallback(Event::CLIENT_DISCONNECT, [$client]);
        }

        unset($this->clients[$client->id]);
    }

    /**
     * Gets all active streams, including server stream.
     * @return array<int, resource> Returns active streams.
     */
    private function getReadableStreams(): array
    {
        $streams = !$this->isShuttingDown ? [$this->stream] : [];

        foreach ($this->clients as $client) {
            if ($client->isConnected) {
                $streams[] = $client->stream;
            }
        }

        return $streams;
    }

    /**
     * Gets all streams that have pending data in their write buffers.
     * @return array<int, resource> Returns streams ready for a write operation.
     */
    private function getWritableStreams(): array
    {
        $streams = [];

        foreach ($this->clients as $client) {
            if ($client->isConnected && $client->hasDataToWrite) {
                $streams[] = $client->stream;
            }
        }

        return $streams;
    }

    /**
     * Gets all clients connected to server.
     * @return array<int, Client> Returns connected clients.
     */
    public function getClients(): array
    {
        $clients = [];

        foreach ($this->clients as $client) {
            if ($client->isConnected && $client->isHandshakePerformed) {
                $clients[] = $client;
            }
        }

        return $clients;
    }

    ///////////// TIMERS ////////////

    /**
     * Creates server timer.
     * @param (\Closure(): void) $function Callback function.
     * @param int $delay Timer delay (in milliseconds).
     * @param bool $isPeriodic Whether timer repeats.
     * @return int Returns timer ID.
     */
    public function setTimer(\Closure $function, int $delay, bool $isPeriodic = false): int
    {
        $this->timers[] = new Timer($function, $delay, $isPeriodic);
        return array_key_last($this->timers);
    }

    /**
     * Cancels server timer.
     * @param int $timerId Timer ID.
     * @return void
     */
    public function clearTimer(int $timerId): void
    {
        if (isset($this->timers[$timerId])) {
            unset($this->timers[$timerId]);
        }
    }

    /**
     * Checks server timers.
     * @return void
     */
    private function checkTimers(): void
    {
        /** @var float $microtime */
        $microtime = microtime(true);

        foreach ($this->timers as $timerId => $timer) {
            if ($timer->tick($microtime)) {
                if (!$timer->isEnabled) {
                    unset($this->timers[$timerId]);
                }
            }
        }
    }

    /**
     * Create internal timers.
     * @return void
     */
    private function setInternalTimers(): void
    {
        $this->setTimer(function (): void {
            foreach ($this->clients as $streamId => $client) {
                $isConnected = $client->isConnected;

                if ($isConnected) {
                    $isConnected = $client->checkTimeouts();
                    if (!$isConnected && $client->isRequestAccepted) {
                        $this->online--;
                        $this->triggerCallback(Event::CLIENT_DISCONNECT, [$client]);
                    }
                }

                if (!$isConnected) {
                    unset($this->clients[$streamId]);
                }
            }
        }, self::INTERVAL_CHECK_TIMEOUTS, true);

        $this->setTimer(function (): void {
            foreach ($this->clients as $client) {
                if ($client->isConnected && $client->isHandshakePerformed) {
                    $client->ping();
                }
            }
        }, self::INTERVAL_PING, true);
    }

    /////////// CALLBACKS ///////////

    /**
     * Registers server callback.
     * @param Event $event Callback event.
     * @param \Closure|null $function Callback function or **NULL** to delete callback.
     * @return void
     */
    private function on(Event $event, ?\Closure $function): void
    {
        if ($function) {
            $this->callbacks[$event->value] = $function;
        } elseif (isset($this->callbacks[$event->value])) {
            unset($this->callbacks[$event->value]);
        }
    }

    /**
     * Triggers server callback.
     * @param Event $event Callback event.
     * @param array $args Callback arguments.
     * @return string|float|int|bool Returns callback result.
     */
    private function triggerCallback(Event $event, array $args = []): string|float|int|bool
    {
        if (isset($this->callbacks[$event->value])) {
            $result = $this->callbacks[$event->value](...$args);
            return $result ?? true;
        }
        return true;
    }

    /**
     * Registers server callback triggered on server start.
     * @param (\Closure(): void)|null $function Callback function.
     * @return void
     */
    public function onServerStart(?\Closure $function): void
    {
        $this->on(Event::SERVER_START, $function);
    }

    /**
     * Registers server callback triggered on server stop.
     * @param (\Closure(): void)|null $function Callback function.
     * @return void
     */
    public function onServerStop(?\Closure $function): void
    {
        $this->on(Event::SERVER_STOP, $function);
    }

    /**
     * Registers server callback triggered on handshake request.
     * @param (\Closure(ClientInterface, RequestInterface): bool)|null $function Callback function.
     * @return void
     */
    public function onHandshake(?\Closure $function): void
    {
        $this->on(Event::HANDSHAKE, $function);
    }

    /**
     * Registers server callback triggered on client connect.
     * @param (\Closure(ClientInterface): void)|null $function Callback function.
     * @return void
     */
    public function onClientConnect(?\Closure $function): void
    {
        $this->on(Event::CLIENT_CONNECT, $function);
    }

    /**
     * Registers server callback triggered on client disconnect.
     * @param (\Closure(ClientInterface): void)|null $function Callback function.
     * @return void
     */
    public function onClientDisconnect(?\Closure $function): void
    {
        $this->on(Event::CLIENT_DISCONNECT, $function);
    }

    /**
     * Registers server callback triggered on message receive.
     * @param (\Closure(ClientInterface, MessageInterface): void)|null $function Callback function.
     * @return void
     */
    public function onMessageReceive(?\Closure $function): void
    {
        $this->on(Event::MESSAGE_RECEIVE, $function);
    }
}
