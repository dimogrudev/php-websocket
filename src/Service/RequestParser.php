<?php

namespace WebSocket\Service;

use WebSocket\Entity\Request;

/**
 * Represents handshake request parser service.
 */
class RequestParser
{
    const string REGEX_REQUEST      = '/^GET\x20([^\s]+)\x20HTTP\/1\.1\r?\n((?:[^\r\n]+\r?\n)+)\r?\n$/';
    const string REGEX_HEADERS      = '/([a-zA-Z0-9!#$%&\'*+.^_`|~-]+)([\x20\t]*):[\x20\t]*(.*?)[\x20\t]*\r?\n/';

    /////////////////////////////////

    /**
     * Parses request instance from raw header data.
     * @param string $raw Raw header data.
     * @return Request|null Returns request instance or **NULL** on failure.
     */
    public function parse(string $raw): ?Request
    {
        $raw = ltrim($raw, "\r\n");

        /** @var array<string, string> $urlParts */
        $urlParts = [];
        /** @var array<string, string> $headers */
        $headers = [];

        if (!$this->parseRaw($raw, $urlParts, $headers)) {
            return null;
        }
        if (!$this->checkRequiredHeaders($headers)) {
            return null;
        }

        $params = $this->parseQueryString($urlParts['query'] ?? '');
        $cookies = $this->parseCookies($headers['cookie'] ?? '');

        return new Request($urlParts['path'], $headers, $params, $cookies);
    }

    /////////////////////////////////

    /**
     * Parses raw header data.
     * @param string $raw Raw header data.
     * @param array<string, string> &$urlParts URL parts.
     * @param array<string, string> &$headers Headers.
     * @return bool Returns **TRUE** on success or **FALSE** otherwise.
     */
    private function parseRaw(string $raw, &$urlParts, &$headers): bool
    {
        if (preg_match(self::REGEX_REQUEST, $raw, $matches)) {
            $requestParts = array_combine(['url', 'headers'], array_slice($matches, 1));

            if ($requestParts) {
                $urlParts = parse_url($requestParts['url']) ?: [];

                if ($urlParts && isset($urlParts['path']) && !isset($urlParts['fragment'])) {
                    return $this->parseHeaders($requestParts['headers'], $headers);
                }
            }
        }

        return false;
    }

    /**
     * Parses raw headers string.
     * @param string $raw Source headers string.
     * @param array<string, string> &$headers Headers.
     * @return bool Returns **TRUE** on success or **FALSE** otherwise.
     */
    private function parseHeaders(string $raw, &$headers): bool
    {
        if (preg_match_all(self::REGEX_HEADERS, $raw, $matches, PREG_SET_ORDER)) {
            $headers = [];

            foreach ($matches as $match) {
                $name = strtolower($match[1]);
                $spacesBeforeColon = $match[2];
                $value = $match[3];

                if ($spacesBeforeColon !== '') {
                    return false;
                }

                if (isset($headers[$name])) {
                    if ($name === 'host') {
                        return false;
                    }

                    $separator = ($name === 'cookie') ? '; ' : ', ';
                    $headers[$name] .= $separator . $value;
                } else {
                    $headers[$name] = $value;
                }
            }
            return true;
        }

        return false;
    }

    /**
     * Determines whether headers match requirements or not.
     * @param array<string, string> $headers Headers array.
     * @return bool Returns **TRUE** on success or **FALSE** otherwise.
     * @see https://datatracker.ietf.org/doc/html/rfc6455#section-4.2.1
     */
    private function checkRequiredHeaders(array $headers): bool
    {
        if (
            // Must have 'Host' header field
            isset($headers['host']) && trim($headers['host']) !== ''
            // Must have 'Upgrade' header field containing value 'websocket'
            && isset($headers['upgrade']) && mb_stripos($headers['upgrade'], 'websocket', encoding: 'ASCII') !== false
            // Must have 'Connection' header field containing value 'Upgrade'
            && isset($headers['connection']) && mb_stripos($headers['connection'], 'upgrade', encoding: 'ASCII') !== false
            // Must have 'Sec-WebSocket-Key' header field
            && isset($headers['sec-websocket-key'])
            // Must have 'Sec-WebSocket-Version' header field, with value of 13
            && isset($headers['sec-websocket-version']) && $headers['sec-websocket-version'] === '13'
        ) {
            $secKey = base64_decode($headers['sec-websocket-key'], true);

            // 'Sec-WebSocket-Key' header field must be base64-encoded 16-byte value
            if ($secKey !== false && strlen($secKey) === 16) {
                return true;
            }
        }
        return false;
    }

    /////////////////////////////////

    /**
     * Parses query parameters from string.
     * @param string $queryString Query string.
     * @return array<string, string|array> Returns query parameters.
     */
    private function parseQueryString(string $queryString): array
    {
        $params = [];
        parse_str($queryString, $params);
        return $params;
    }

    /**
     * Parses cookies from string.
     * @param string $cookieHeader Cookie header.
     * @return array<string, string> Returns cookies.
     */
    private function parseCookies(string $cookieHeader): array
    {
        $cookies = [];

        foreach ((preg_split('/;\x20?/', $cookieHeader) ?: []) as $cookie) {
            $cookie = urldecode($cookie);

            if (preg_match('/^([^=]+)=(.*)$/', trim($cookie), $matches)) {
                $cookies[$matches[1]] = $matches[2];
            }
        }

        return $cookies;
    }
}
