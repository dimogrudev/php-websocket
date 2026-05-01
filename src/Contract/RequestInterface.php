<?php

namespace WebSocket\Contract;

/**
 * Represents request interface for public API.
 */
interface RequestInterface
{
    /** @var string $path Request path. */
    public string $path { get; }

    /**
     * Gets header value.
     * @param string $name Header name.
     * @return string|null Returns header value or **NULL** on failure.
     */
    public function header(string $name): ?string;
    /**
     * Gets query value.
     * @param string $name Query parameter.
     * @return string|array|null Returns query value or **NULL** on failure.
     */
    public function query(string $name): string|array|null;
    /**
     * Gets cookie value.
     * @param string $name Cookie name.
     * @return string|null Returns cookie value or **NULL** on failure.
     */
    public function cookie(string $name): ?string;
}
