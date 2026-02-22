<?php

declare(strict_types=1);

namespace Flows\Reactor;

class Reactor
{
    private array $readHandlers = [];
    private array $writeHandlers = [];
    private array $timers = []; // [ [float $time, callable $callback, bool $repeat, float $interval], ... ]

    public function onReadable($stream, callable $handler): void
    {
        $this->readHandlers[(int)$stream] = [$stream, $handler];
    }

    public function onWritable($stream, callable $handler): void
    {
        $this->writeHandlers[(int)$stream] = [$stream, $handler];
    }

    public function remove($stream): void
    {
        if (isset($this->readHandlers[(int)$stream])) {
            unset($this->readHandlers[(int)$stream]);
        }
        if (isset($this->writeHandlers[(int)$stream])) {
            unset($this->writeHandlers[(int)$stream]);
        }
    }

    public function addTimer(float $interval, callable $callback, bool $repeat = false): void
    {
        $this->timers[] = [microtime(true) + $interval, $callback, $repeat, $interval];
    }

    private function runTimers(): void
    {
        $now = microtime(true);
        foreach ($this->timers as $i => [$time, $callback, $repeat, $interval]) {
            if ($now >= $time) {
                $callback($this);
                if ([] === $this->timers) {
                    // stopRun() called in callback, nothing else to do
                    return;
                }
                if ($repeat) {
                    $this->timers[$i][0] = $now + $interval;
                } else {
                    unset($this->timers[$i]);
                }
            }
        }
        // Re-index
        $this->timers = array_values($this->timers);
    }

    public function run(): void
    {
        while (!empty($this->readHandlers) || !empty($this->writeHandlers) || !empty($this->timers)) {
            $timeout = null;
            // Compute next timer deadline
            if (!empty($this->timers)) {
                $nextDue = min(array_column($this->timers, 0));
                $timeout = max(0, $nextDue - microtime(true));
            }

            $read = array_column($this->readHandlers, 0);
            $write = array_column($this->writeHandlers, 0);
            $except = null;
            // Wait for streams or timer expiry
            if ($read || $write) {
                stream_select($read, $write, $except, $timeout === null ? null : (int)$timeout, $timeout !== null ? (int)(($timeout - (int)$timeout) * 1e6) : 0);
            } else {
                // No streams, just sleep until next timer
                if ($timeout !== null) {
                    usleep((int)($timeout * 1e6));
                }
            }
            // Handle I/O
            foreach ($read as $r) {
                [$stream, $handler] = $this->readHandlers[(int)$r];
                $handler($stream, $this);
            }
            foreach ($write as $w) {
                [$stream, $handler] = $this->writeHandlers[(int)$w];
                $handler($stream, $this);
            }
            // Handle timers
            $this->runTimers();
        }
    }

    public function stopRun(): void
    {
        $this->readHandlers = $this->writeHandlers = $this->timers = [];
    }

    /**
     * Check if a stream has a handler registered, either for reading or writing
     * 
     * @param resource $stream
     * @return bool
     */
    public function hasHandler($stream): bool
    {
        return isset($this->readHandlers[(int)$stream])
            || isset($this->writeHandlers[(int)$stream]);
    }

    /**
     * Check if there are any handlers registered, either for reading or writing
     * 
     * @return bool
     */
    public function hasHandlers(): bool
    {
        return !([] === $this->readHandlers && [] === $this->writeHandlers);
    }

    /**
     * Check if there are any timers registered
     * 
     * @return bool
     */
    public function hasTimers(): bool
    {
        return !([] === $this->timers);
    }
}
