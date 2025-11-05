<?php

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
