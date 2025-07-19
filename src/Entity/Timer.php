<?php

namespace WebSocket\Entity;

/**
 * Represents timer entity
 */
class Timer
{
    /** @var bool $enabled Timer is enabled */
    private(set) bool $enabled  = true;
    /** @var float $executedAt Last execution timestamp */
    private float $executedAt;

    /**
     * @param \Closure $function Timer function
     * @param int $delay Timer delay (in milliseconds)
     * @param bool $repeat Run timer repeatedly
     * @return void
     */
    public function __construct(
        private \Closure $function,
        private int $delay,
        private bool $repeat
    ) {
        $this->executedAt = microtime(true);
    }

    /**
     * Checks whether timer can be executed or not
     * @param float|null $microtime Current timestamp with microseconds
     * @return bool Returns **TRUE** if timer is executed or **FALSE** otherwise
     */
    public function checkDelay(?float $microtime = null): bool
    {
        if ($this->enabled) {
            if ($microtime === null) {
                /** @var float $microtime */
                $microtime = microtime(true);
            }

            if (($microtime - $this->executedAt) * 1000 >= $this->delay) {
                ($this->function)();

                $this->enabled = $this->repeat;
                $this->executedAt = $microtime;

                return true;
            }
        }

        return false;
    }
}
