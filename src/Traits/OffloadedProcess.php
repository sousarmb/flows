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

namespace Flows\Traits;

use LogicException;

trait OffloadedProcess
{
    /**
     * 
     * @param $process resource
     * @return int|null null if process still running
     */
    public function getExitCode($process): ?int
    {
        return $this->isRunning($process) ? null : proc_get_status($process)['exitcode'];
    }

    /**
     * 
     * @param resource $process
     * @return bool
     * @throws LogicException
     */
    public function isRunning($process): bool
    {
        if (is_resource($process)) {
            return proc_get_status($process)['running'];
        }

        throw new LogicException('Input is not resource');
    }

    /**
     * 
     * @param resource $process
     * @return int|null null if process is no longer running
     */
    public function closeProcess($process): ?int
    {
        return $this->isRunning($process) ? proc_close($process) : null;
    }

    /**
     * 
     * @param resource $process
     * @return bool|null null if process is no longer running
     */
    public function terminateProcess($process): ?bool
    {
        return $this->isRunning($process) ? proc_terminate($process) : null;
    }

    /**
     * 
     * @param array<int, resource> $processes
     * @return array<int, array<int, int|null>>
     */
    public function closeProcesses(array $processes): array
    {
        return array_map(fn($proc) => [(int)$proc => $this->closeProcess($proc)], $processes);
    }

    /**
     * 
     * @param array<int, resource> $processes
     * @return array<int, array<int, bool|null>>
     */
    public function terminateProcesses(array $processes): array
    {
        return array_map(fn($proc) => [(int)$proc => $this->terminateProcess($proc)], $processes);
    }
}
