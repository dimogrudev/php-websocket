<?php

namespace WebSocket\Infrastructure;

/**
 * Represents timer entity.
 */
class Timer
{
    /** @var bool $isEnabled Whether timer is enabled. */
    private(set) bool $isEnabled  = true;
    /** @var float $executedAt Last execution timestamp. */
    private float $executedAt;

    /**
     * @param \Closure $function Timer callback.
     * @param int $delay Timer delay (in milliseconds).
     * @param bool $isPeriodic Whether timer repeats.
     * @param float|null $microtime Current timestamp with microseconds.
     */
    public function __construct(
        private readonly \Closure $function,
        private readonly int $delay,
        private readonly bool $isPeriodic,
        ?float $microtime = null
    ) {
        $this->executedAt = $microtime ?? microtime(true);
    }

    /**
     * Evaluates timer state and triggers execution if delay has expired.
     * @param float|null $microtime Current timestamp with microseconds.
     * @return bool Returns **TRUE** if registered callback is executed or **FALSE** otherwise.
     */
    public function tick(?float $microtime = null): bool
    {
        if ($this->isEnabled) {
            /** @var float $microtime */
            $microtime ??= microtime(true);

            if (($microtime - $this->executedAt) * 1000 >= $this->delay) {
                $this->execute($microtime);
                return true;
            }
        }

        return false;
    }

    /**
     * Executes registered callback and updates timer state.
     * @param float $microtime Current timestamp with microseconds.
     * @return void
     */
    private function execute(float $microtime): void
    {
        ($this->function)();

        $this->isEnabled = $this->isPeriodic;
        $this->executedAt = $microtime;
    }
}
