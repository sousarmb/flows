<?php

/*
 * The MIT License
 *
 * Copyright 2024 rsousa <rmbsousa@gmail.com>.
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */

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
        unset($this->readHandlers[(int)$stream], $this->writeHandlers[(int)$stream]);
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
                if ($repeat) {
                    $this->timers[$i][0] = $now + $interval;
                } else {
                    unset($this->timers[$i]);
                }
            }
        }
        // reindex
        $this->timers = array_values($this->timers);
    }

    public function run(): void
    {
        while (!empty($this->readHandlers) || !empty($this->writeHandlers) || !empty($this->timers)) {
            $timeout = null;
            // compute next timer deadline
            if (!empty($this->timers)) {
                $nextDue = min(array_column($this->timers, 0));
                $timeout = max(0, $nextDue - microtime(true));
            }

            $read = array_column($this->readHandlers, 0);
            $write = array_column($this->writeHandlers, 0);
            $except = null;
            // wait for streams or timer expiry
            if ($read || $write) {
                stream_select($read, $write, $except, $timeout === null ? null : (int)$timeout, $timeout !== null ? (int)(($timeout - (int)$timeout) * 1e6) : 0);
            } else {
                // no streams, just sleep until next timer
                if ($timeout !== null) {
                    usleep((int)($timeout * 1e6));
                }
            }
            // handle I/O
            foreach ($read as $r) {
                [$stream, $handler] = $this->readHandlers[(int)$r];
                $handler($stream, $this);
            }
            foreach ($write as $w) {
                [$stream, $handler] = $this->writeHandlers[(int)$w];
                $handler($stream, $this);
            }
            // handle timers
            $this->runTimers();
        }
    }

    public function stopRun(): void
    {
        $this->readHandlers = $this->writeHandlers = $this->timers = [];
    }
}
