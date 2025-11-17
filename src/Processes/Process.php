<?php

declare(strict_types=1);

namespace Flows\Processes;

use Collectibles\Collection;
use Collectibles\Contracts\IO;
use Flows\Contracts\Gate;
use Flows\Contracts\Tasks\Task;
use Flows\Event\Kernel as EventKernel;
use Flows\Facades\Container;
use Flows\Factory;
use Flows\Gates\OrGate;
use Flows\Gates\XorGate;
use Flows\Observer\Kernel as ObserverKernel;
use LogicException;

abstract class Process
{
    protected array $tasks = [];
    private ?EventKernel $events = null;
    private ?ObserverKernel $observers = null;

    /**
     *
     * @throws LogicException
     */
    public function __construct()
    {
        if ([] === $this->tasks) {
            throw new LogicException('Invalid process: no tasks to process');
        }

        foreach ($this->tasks as &$task) {
            if (
                is_string($task)
                && class_exists($task)
            ) {
                $task = Factory::getClassInstance($task, $task);
            }
            if (!$task instanceof Task && !$task instanceof Gate) {
                throw new LogicException('Invalid task: must be instance of Task or Gate classes');
            }
        }
        // Is flows booting?
        if (Container::isReady()) {
            // no, already booted
            $this->events = Container::get(EventKernel::class);
            $this->observers = Container::get(ObserverKernel::class);
        }
    }

    /**
     *
     * @return void
     */
    public function cleanUp(): void
    {
        $this->events?->handleDeferFromProcess();
        $this->observers?->observe($this);
        $this->observers?->handleDeferFromProcess();
        for ($i = count($this->tasks) - 1; $i >= 0; $i--) {
            $this->tasks[$i]->cleanUp();
        }
    }

    /**
     *
     * @return bool
     */
    public function done(): bool
    {
        return key($this->tasks) === null;
    }

    /**
     *
     * @return int|null
     */
    public function getCurrentTaskKey(): ?int
    {
        return key($this->tasks);
    }

    /**
     *
     * @param IO|null $io
     * @return Gate|IO|null
     * @throws LogicException
     */
    public function process(?IO $io = null): Gate|IO|null
    {
        if ($this->done()) {
            throw new LogicException('These tasks are done');
        }

        $current = reset($this->tasks);
        do {
            if ($current instanceof XorGate) {
                // XorGate always ends the process
                end($this->tasks);
                $this->makeObservation($current->setIO($io));
                return $current;
            } elseif ($current instanceof OrGate) {
                $this->makeObservation($current->setIO($io));
                // Always advance past OrGate - prevents infinite loop on first task
                next($this->tasks);
                return $current;
            } else {
                // Regular task
                $io = $current($io);
                $this->makeObservation($io);
            }
        } while ($current = next($this->tasks));

        return $io;
    }

    /**
     *
     * @param Collection|null $io
     * @return Gate|IO|null
     * @throws LogicException
     */
    public function resume(?Collection $io = null): Gate|IO|null
    {
        // Watch out! May be out of bounds!
        if ($this->done()) {
            throw new LogicException('These tasks are done');
        }

        $current = current($this->tasks);
        do {
            if ($current instanceof XorGate) {
                end($this->tasks);
                $this->makeObservation($current->setIO($io));
                return $current;
            } elseif ($current instanceof OrGate) {
                $this->makeObservation($current->setIO($io));
                // Prevent infine loop when first task is a Or typed gate
                // (array pointer must advance so application kernel selects "resume process" switch)
                // (task key > 0)
                next($this->tasks);
                return $current;
            } elseif ($current) {
                // ... but if not, process the task
                $io = $current($io);
                $this->makeObservation($io);
            }
        } while ($current = next($this->tasks));

        return $io;
    }

    private function makeObservation(object $subject): void
    {
        $this->observers?->observe($subject);
    }
}
