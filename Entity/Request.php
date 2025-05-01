<?php

namespace Entity;

/**
 * Represents request entity
 */
class Request
{
    const int MAX_LENGTH            = 2048;

    const string REGEX_REQUEST      = '/^(?:GET)\x20(.+)\x20(?:HTTP\/[\d\.]+)(?:\r\n|\n|\r)((?:\S+:\x20.*(?:\r\n|\n|\r))+)/';
    const string REGEX_HEADERS      = '/(\S+):\x20(.*)(?:\r\n|\n|\r)/';

    /** @var array $params Query parameters */
    private array $params           = [];
    /** @var array $cookies Cookies */
    private array $cookies          = [];

    /**
     * Class constructor
     * @param string $path Path
     * @param array $headers Headers
     * @return void
     */
    private function __construct(
        private(set) string $path,
        private array $headers
    ) {}

    /**
     * Parses query parameters from string
     * @param string $queryString Query string
     * @return void
     */
    private function parseQueryString(string $queryString): void
    {
        $this->params = [];

        parse_str(
            urldecode($queryString),
            $this->params
        );
    }

    /**
     * Parses cookies from string
     * @param string $cookieHeader Cookie header
     * @return void
     */
    private function parseCookies(string $cookieHeader): void
    {
        $this->cookies = [];

        foreach ((preg_split('/;\x20?/', $cookieHeader) ?: []) as $cookie) {
            $cookie = urldecode($cookie);

            if (preg_match('/^(.+)=(.+)$/', trim($cookie), $matches)) {
                $this->cookies[$matches[1]] = $matches[2];
            }
        }
    }

    /**
     * Gets header value
     * @param string $name Header name
     * @return string|null Returns header value or **NULL** on failure
     */
    public function header(string $name): string|null
    {
        $name = strtolower($name);

        if (isset($this->headers[$name])) {
            return $this->headers[$name];
        }
        return null;
    }

    /**
     * Gets query value
     * @param string $name Query parameter
     * @return string|array|null Returns query value or **NULL** on failure
     */
    public function query(string $name): string|array|null
    {
        $name = strtolower($name);

        if (isset($this->params[$name])) {
            return $this->params[$name];
        }
        return null;
    }

    /**
     * Gets cookie value
     * @param string $name Cookie name
     * @return string|array|null Returns cookie value or **NULL** on failure
     */
    public function cookie(string $name): string|array|null
    {
        $name = strtolower($name);

        if (isset($this->cookies[$name])) {
            return $this->cookies[$name];
        }
        return null;
    }

    /**
     * Receives request information
     * @param resource $stream Source stream
     * @return self|null Returns request instance or **NULL** on failure
     */
    public static function receive($stream): self|null
    {
        $buffer = fread($stream, self::MAX_LENGTH);

        if ($buffer) {
            if (
                self::parseBuffer($buffer, $urlParts, $headers)
                && self::checkRequiredHeaders($headers)
            ) {
                $entity = new self($urlParts['path'], $headers);

                if (isset($urlParts['query'])) {
                    $entity->parseQueryString($urlParts['query']);
                }
                if (isset($headers['cookie'])) {
                    $entity->parseCookies($headers['cookie']);
                }

                return $entity;
            }
        }

        return null;
    }

    /**
     * Parses raw buffer
     * @param string $buffer Source buffer
     * @param array &$urlParts URL parts
     * @param array &$headers Headers
     * @return bool Returns **TRUE** on success or **FALSE** otherwise
     */
    private static function parseBuffer(string $buffer, &$urlParts, &$headers): bool
    {
        if (preg_match(self::REGEX_REQUEST, $buffer, $matches)) {
            $requestParts = array_combine(['url', 'headers'], array_slice($matches, 1));

            if ($requestParts) {
                $urlParts = parse_url($requestParts['url']);

                if ($urlParts && isset($urlParts['path']) && !isset($urlParts['fragment'])) {
                    return self::parseHeaders($requestParts['headers'], $headers);
                }
            }
        }

        return false;
    }

    /**
     * Parses raw headers string
     * @param string $raw Source headers string
     * @param array &$headers Headers
     * @return bool Returns **TRUE** on success or **FALSE** otherwise
     */
    private static function parseHeaders(string $raw, &$headers): bool
    {
        if (preg_match_all(self::REGEX_HEADERS, $raw, $matches)) {
            $headers = array_combine(
                array_map('strtolower', $matches[1]),
                array_map('rtrim', $matches[2])
            ) ?: [];
            return true;
        }

        return false;
    }

    /**
     * Determines whether headers match requirements or not
     * @return bool Returns **TRUE** on success or **FALSE** otherwise
     * @see https://datatracker.ietf.org/doc/html/rfc6455#section-4.2.1
     */
    private static function checkRequiredHeaders(array $headers): bool
    {
        if (
            // Must have 'Host' header field
            isset($headers['host']) && $headers['host']
            // Must have 'Upgrade' header field containing value 'websocket'
            && isset($headers['upgrade']) && mb_stripos($headers['upgrade'], 'websocket', encoding: 'ASCII') !== false
            // Must have 'Connection' header field containing value 'Upgrade'
            && isset($headers['connection']) && mb_stripos($headers['connection'], 'upgrade', encoding: 'ASCII') !== false
            // Must have 'Sec-WebSocket-Key' header field
            && isset($headers['sec-websocket-key']) && $headers['sec-websocket-key']
            // Must have 'Sec-WebSocket-Version' header field, with value of 13
            && isset($headers['sec-websocket-version']) && $headers['sec-websocket-version'] == '13'
        ) {
            $secKey = base64_decode($headers['sec-websocket-key'], true);

            // 'Sec-WebSocket-Key' header field must be base64-encoded 16-byte value
            if ($secKey && mb_strlen($secKey, '8bit') == 16) {
                return true;
            }
        }
        return false;
    }
}
