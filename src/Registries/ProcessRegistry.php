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
     * @param string $classNameProcess
     * @return Process
     * @throws LogicException
     */
    public function getNamed(string $classNameProcess): Process
    {
        if ($this->exists($classNameProcess)) {
            return $this->current = $this->processes[$classNameProcess];
        }

        throw new LogicException("Unregistered process $classNameProcess");
    }

    /**
     *
     * @param string $classNameProcess
     * @return bool
     */
    public function exists(string $classNameProcess): bool
    {
        return array_key_exists($classNameProcess, $this->processes);
    }
}
