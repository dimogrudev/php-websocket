<?php

namespace Core\Modules;

/**
 * Represents process locking module
 */
final class Process
{
    const string LOCKFILE_PATH      = '/LOCK';

    /** @var int $pid Process ID */
    private static int $pid;
    /** @var int $signaledAt Time of process signaling */
    private static int $signaledAt;

    /**
     * Checks if process is locked
     * @return bool Returns **TRUE** if process is locked, **FALSE** otherwise
     */
    public static function isLocked(): bool
    {
        if (function_exists('posix_getsid') && function_exists('posix_kill')) {
            $lockfile = __DIR__ . '/../..' . self::LOCKFILE_PATH;

            if (file_exists($lockfile)) {
                $json = file_get_contents($lockfile);

                if (is_string($json) && json_validate($json)) {
                    $properties = json_decode($json, true);

                    if (is_array($properties)) {
                        foreach ($properties as $name => $value) {
                            self::$$name = $value;
                        }

                        if (
                            isset(self::$pid) && isset(self::$signaledAt)
                            && posix_getsid(self::$pid) !== false
                        ) {
                            if ((time() - self::$signaledAt) < 30) {
                                return true;
                            } else {
                                posix_kill(self::$pid, SIGTERM);
                                sleep(5);

                                if (posix_getsid(self::$pid) !== false) {
                                    posix_kill(self::$pid, SIGKILL);
                                }
                            }
                        }
                    }
                }
            }
        }

        return false;
    }

    /**
     * Locks process
     * @return void
     */
    public static function lock(): void
    {
        $processId = getmypid();

        if ($processId !== false) {
            self::$pid = $processId;
            self::$signaledAt = time();
            self::updateLockfile();
        } else {
            throw new \RuntimeException('Couldn\'t get process ID');
        }
    }

    /**
     * Sends signal from process
     * @return void
     */
    public static function signal(): void
    {
        if (isset(self::$pid)) {
            self::$signaledAt = time();
            self::updateLockfile();
        } else {
            throw new \RuntimeException('Process is not locked');
        }
    }

    /**
     * Gets process ID
     * @return int Returns process ID
     */
    public static function getPid(): int
    {
        if (isset(self::$pid)) {
            return self::$pid;
        }
        throw new \RuntimeException('Process is not locked');
    }

    /**
     * Updates lockfile
     * @return void
     */
    private static function updateLockfile(): void
    {
        $class = new \ReflectionClass(self::class);
        file_put_contents(
            __DIR__ . '/../..' . self::LOCKFILE_PATH,
            json_encode($class->getStaticProperties(), JSON_PRETTY_PRINT)
        );
    }
}
