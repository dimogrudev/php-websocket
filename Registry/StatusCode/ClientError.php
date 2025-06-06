<?php

namespace Registry\StatusCode;

/**
 * Represents client error response registry
 * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Status#client_error_responses
 */
enum ClientError: int
{
    case BAD_REQUEST    = 400;
    case UNAUTHORIZED   = 401;
    case FORBIDDEN      = 403;
    case NOT_FOUND      = 404;

    /**
     * Gets status name
     * @return string Returns status name
     */
    public function getStatus(): string
    {
        return match ($this) {
            self::BAD_REQUEST   => 'Bad Request',
            self::UNAUTHORIZED  => 'Unauthorized',
            self::FORBIDDEN     => 'Forbidden',
            self::NOT_FOUND     => 'Not Found'
        };
    }
}
