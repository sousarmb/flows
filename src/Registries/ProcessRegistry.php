<?php

declare(strict_types=1);

namespace Flows\Registries;

use Flows\Factory;
use Flows\Processes\Process;
use LogicException;

class ProcessRegistry
{
    private array $processes = [];
    private Process $current;

    /**
     *
     * @param Process|string $process
     * @return self
     */
    public function add(Process|string $process): self
    {
        if (is_string($process)) {
            $process = Factory::getClassInstance($process);
        }

        $this->processes[get_class($process)] = $process;
        return $this;
    }

    /**
     *
     * @return Process
     */
    public function getCurrentProcess(): Process
    {
        return $this->current;
    }

    /**
     *
     * @param string $nsProcess
     * @return Process
     * @throws LogicException
     */
    public function getNamed(string $nsProcess): Process
    {
        if ($this->exists($nsProcess)) {
            return $this->current = $this->processes[$nsProcess];
        }

        throw new LogicException("Unregistered process $nsProcess");
    }

    /**
     *
     * @param string $nsProcess
     * @return bool
     */
    public function exists(string $nsProcess): bool
    {
        return array_key_exists($nsProcess, $this->processes);
    }
}
