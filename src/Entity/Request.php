<?php

namespace WebSocket\Entity;

use WebSocket\Contract\RequestInterface;

/**
 * Represents handshake request value object.
 */
readonly class Request implements RequestInterface
{
    /**
     * @param string $path Request path.
     * @param array<string, string> $headers Headers.
     * @param array<string, string|array> $params Query parameters.
     * @param array<string, string> $cookies Cookies.
     */
    public function __construct(
        public string $path,
        private array $headers,
        private array $params = [],
        private array $cookies = []
    ) {}

    /////////////////////////////////

    /**
     * Gets header value.
     * @param string $name Header name (case-insensitive).
     * @return string|null Returns header value or **NULL** on failure.
     */
    public function header(string $name): ?string
    {
        $name = strtolower($name);

        if (isset($this->headers[$name])) {
            return $this->headers[$name];
        }
        return null;
    }

    /**
     * Gets query value.
     * @param string $name Query parameter (case-sensitive).
     * @return string|array|null Returns query value or **NULL** on failure.
     */
    public function query(string $name): string|array|null
    {
        if (isset($this->params[$name])) {
            return $this->params[$name];
        }
        return null;
    }

    /**
     * Gets cookie value.
     * @param string $name Cookie name (case-sensitive).
     * @return string|null Returns cookie value or **NULL** on failure.
     */
    public function cookie(string $name): ?string
    {
        if (isset($this->cookies[$name])) {
            return $this->cookies[$name];
        }
        return null;
    }
}
