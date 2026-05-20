<?php

namespace WebSocket\Protocol\Registry;

/**
 * Represents registry for close status codes.
 * @see https://datatracker.ietf.org/doc/html/rfc6455#section-11.7
 */
enum CloseCode: int
{
    case NORMAL_CLOSURE             = 1000;
    case GOING_AWAY                 = 1001;
    case PROTOCOL_ERROR             = 1002;
    case UNSUPPORTED_DATA           = 1003;
    case INVALID_FRAME_PAYLOAD_DATA = 1007;
    case POLICY_VIOLATION           = 1008;
    case MESSAGE_TOO_BIG            = 1009;
    case INTERNAL_SERVER_ERROR      = 1011;

    /**
     * Gets status code description.
     * @return string Returns status code description.
     */
    public function getDescription(): string
    {
        return match ($this) {
            self::NORMAL_CLOSURE                => 'Normal closure',
            self::GOING_AWAY                    => 'Endpoint is going away',
            self::PROTOCOL_ERROR                => 'Protocol error',
            self::UNSUPPORTED_DATA              => 'Unacceptable data type',
            self::INVALID_FRAME_PAYLOAD_DATA    => 'Invalid frame payload data',
            self::POLICY_VIOLATION              => 'Policy violation',
            self::MESSAGE_TOO_BIG               => 'Message too big',
            self::INTERNAL_SERVER_ERROR         => 'Internal server error'
        };
    }
}
