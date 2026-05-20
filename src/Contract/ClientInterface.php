<?php

namespace WebSocket\Contract;

use WebSocket\Infrastructure\Http\Registry\ClientError;
use WebSocket\Infrastructure\Http\Registry\Redirection;
use WebSocket\Protocol\Registry\CloseCode;

/**
 * Represents client interface for public API.
 */
interface ClientInterface
{
    /** @var int $id Client stream ID. */
    public int $id { get; }
    /** @var string $ipAddr Client IP address. */
    public string $ipAddr { get; }
    /** @var bool $isConnected Whether connection is established. */
    public bool $isConnected { get; }

    /**
     * Disconnects client.
     * @param CloseCode|null $code Status code to be send before disconnecting or **NULL** to close connection immediately.
     * @param string|null $reason Human-readable explanation for closure.
     * @param bool $forceClose Whether to completely close connection after status code is sent.
     * @return void
     */
    public function disconnect(?CloseCode $code = null, ?string $reason = null, bool $forceClose = false): void;
    /**
     * Sends redirection header to the client.
     * @return void
     */
    public function redirect(Redirection $code, string $location): void;
    /**
     * Sends error header to the client.
     * @return void
     */
    public function error(ClientError $code): void;

    /**
     * Sends data message to the client.
     * @param string $payload Message content to be transmitted.
     * @param bool $isBinary Whether message content is binary.
     * @return void
     */
    public function send(string $payload, bool $isBinary = false): void;
}
