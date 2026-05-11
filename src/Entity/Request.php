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
        return $this->headers[$name] ?? null;
    }

    /**
     * Gets query value.
     * @param string $name Query parameter (case-sensitive).
     * @return string|array|null Returns query value or **NULL** on failure.
     */
    public function query(string $name): string|array|null
    {
        return $this->params[$name] ?? null;
    }

    /**
     * Gets cookie value.
     * @param string $name Cookie name (case-sensitive).
     * @return string|null Returns cookie value or **NULL** on failure.
     */
    public function cookie(string $name): ?string
    {
        return $this->cookies[$name] ?? null;
    }
}
