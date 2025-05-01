<?php

namespace Registry\StatusCode;

/**
 * Represents redirection message registry
 * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Status#redirection_messages
 */
enum Redirection: int
{
    case MOVED_PERMANENTLY  = 301;
    case FOUND              = 302;
    case TEMPORARY_REDIRECT = 307;

    /**
     * Gets status name
     * @return string Returns status name
     */
    public function getStatus(): string
    {
        return match ($this) {
            self::MOVED_PERMANENTLY     => 'Moved Permanently',
            self::FOUND                 => 'Found',
            self::TEMPORARY_REDIRECT    => 'Temporary Redirect'
        };
    }
}
