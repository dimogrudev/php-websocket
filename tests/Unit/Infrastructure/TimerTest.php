<?php

namespace WebSocket\Test\Unit\Infrastructure;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WebSocket\Infrastructure\Timer;

class TimerTest extends TestCase
{
    private const float BASE_TIME = 1000.0;

    public static function timerScenarioProvider(): array
    {
        return [
            'before delay'      => [100, false, 0.050, false, true],
            'exact delay'       => [100, false, 0.100, true, false],
            'after delay'       => [100, false, 0.150, true, false],
            'periodic remains'  => [100, true, 0.100, true, true],
        ];
    }

    #[DataProvider('timerScenarioProvider')]
    public function testTimerExecutionLogic(
        int $delay,
        bool $isPeriodic,
        float $checkTimeOffset,
        bool $expectedResult,
        bool $expectedEnabled
    ): void {
        $fired = false;

        $callback = function () use (&$fired): void {
            $fired = true;
        };
        $timer = new Timer($callback, $delay, $isPeriodic, self::BASE_TIME);

        $result = $timer->tick(self::BASE_TIME + $checkTimeOffset);

        $this->assertSame($expectedResult, $result, 'Return value of tick() is incorrect.');
        $this->assertSame($expectedResult, $fired, 'Callback execution state mismatch.');
        $this->assertSame($expectedEnabled, $timer->isEnabled, 'Timer enabled state mismatch.');
    }

    public function testPeriodicTimerCycles(): void
    {
        $executions = 0;

        $callback = function () use (&$executions): void {
            $executions++;
        };
        $timer = new Timer($callback, 100, true, self::BASE_TIME);

        $timer->tick(self::BASE_TIME + 0.100);
        $timer->tick(self::BASE_TIME + 0.150);
        $timer->tick(self::BASE_TIME + 0.200);

        $this->assertSame(2, $executions, 'Periodic timer should have fired twice.');
    }
}
