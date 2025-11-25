<?php

declare(strict_types=1);

namespace Flows\Registries;

use Flows\Attributes\Singleton;
use Flows\Factory;
use Flows\Processes\Process;
use LogicException;
use ReflectionClass;

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
        if ($process instanceof Process) {
            $reflection = new ReflectionClass($process);
        } else {
            if (
                !class_exists($process)
                || !is_subclass_of($process, Process::class)
            ) {
                // Fail early
                throw new LogicException("Trying to register invalid process: {$process}");
            }

            $reflection = new ReflectionClass($process);
        }

        $attrib = $reflection->getAttributes(Singleton::class);
        $isSingleton = [] === $attrib
            ? false
            : $attrib[0]->newInstance()->getIsSingleton();
        $className = $reflection->getName();
        $this->processes[$className] = $isSingleton
            ? ($process instanceof Process ? $process : Factory::getClassInstance($className)) // Registered as a singleton
            : $className; // Not registered as a singleton

        return $this;
    }

    /**
     *
     * Get the current process class name or instance 
     * 
     * @param bool $nameOrInstance TRUE => instance, FALSE => class name
     * @return Process
     */
    public function getCurrentProcess(bool $nameOrInstance = false): Process
    {
        return $nameOrInstance ? $this->current : get_class($this->current);
    }

    /**
     *
     * @param string $nsProcess
     * @return Process
     * @throws LogicException
     */
    public function getNamed(string $nsProcess): Process
    {
        if (isset($this->processes[$nsProcess])) {
            return $this->current = $this->processes[$nsProcess] instanceof Process
                ? $this->processes[$nsProcess] // Registered as a singleton
                : Factory::getClassInstance($this->processes[$nsProcess]); // Not registered as a singleton
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
        return isset($this->processes[$nsProcess]);
    }
}
