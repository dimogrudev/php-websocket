<?php

namespace WebSocket\Entity;

/**
 * Represents timer entity
 */
class Timer
{
    /** @var bool $isEnabled Whether timer is enabled */
    private(set) bool $isEnabled  = true;
    /** @var float $executedAt Last execution timestamp */
    private float $executedAt;

    /**
     * @param \Closure $function Timer function
     * @param int $delay Timer delay (in milliseconds)
     * @param bool $isPeriodic Whether timer repeats
     */
    public function __construct(
        private readonly \Closure $function,
        private readonly int $delay,
        private readonly bool $isPeriodic
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
        if ($this->isEnabled) {
            if ($microtime === null) {
                /** @var float $microtime */
                $microtime = microtime(true);
            }

            if (($microtime - $this->executedAt) * 1000 >= $this->delay) {
                ($this->function)();

                $this->isEnabled = $this->isPeriodic;
                $this->executedAt = $microtime;

                return true;
            }
        }

        return false;
    }
}
