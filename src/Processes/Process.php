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
    /**
     * @var Flag TRUE when serialize called on this object, FALSE after waking up
     */
    private bool $serialize = false;
    private int $position = 0;
    private int $taskCount = 0;
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
            if (
                !$task instanceof Task
                && !$task instanceof Gate
            ) {
                throw new LogicException('Invalid task: must be instance of Task or Gate classes');
            }
        }

        $this->taskCount = count($this->tasks);
        // Bind kernels if container is ready
        if (Container::isReady()) {
            $this->events = Container::get(EventKernel::class);
            $this->observers = Container::get(ObserverKernel::class);
        }
    }

    /**
     *
     * Run task cleanup, last to first (includes tasks not processed)
     * 
     * @return void
     */
    public function cleanUp(): void
    {
        $this->events?->handleDeferFromProcess();
        $this->observers?->observe($this);
        $this->observers?->handleDeferFromProcess();
        for ($i = $this->taskCount - 1; $i >= 0; $i--) {
            $this->tasks[$i]->cleanUp();
        }
    }

    /**
     *
     * @return bool
     */
    public function done(): bool
    {
        return $this->position >= $this->taskCount;
    }

    /**
     *
     * @return int|null  Null when process is done, tasks position otherwise
     */
    public function getPosition(): ?int
    {
        return $this->done()
            ? null
            : $this->position;
    }

    /**
     * 
     * @return array<int|null, int> Where 0 => current task index or null if 
     *                              process completed, 1 => task count
     */
    public function getProgress(): array
    {
        return [$this->position, $this->taskCount];
    }

    /**
     *
     * @param IO|null $io
     * @return Gate|IO|null
     * @throws LogicException
     */
    private function handle(?IO $io = null): Gate|IO|null
    {
        do {
            $work = $this->tasks[$this->position];
            if ($work instanceof XorGate) {
                $this->makeObservation($work->setIO($io));
                // XorGate always ends the process
                $this->position = $this->taskCount;
                return $work;
            } elseif ($work instanceof OrGate) {
                $this->makeObservation($work->setIO($io));
                // Always advance past OrGate (prevents infinite loop on first task)
                $this->position++;
                return $work;
            } else {
                // Regular task, do the work
                $io = $work($io);
                $this->makeObservation($io);
                $this->position++;
            }
        } while ($this->position < $this->taskCount);
        return $io;
    }

    /**
     * 
     * Start from first task
     *
     * @param Collection|null $io
     * @return Gate|IO|null
     * @throws LogicException
     */
    public function run(?IO $io = null): Gate|IO|null
    {
        if ($this->done()) {
            throw new LogicException('Process already completed');
        }

        $this->position = 0;
        return $this->handle($io);
    }

    /**
     *
     * Resume where left
     * 
     * @param IO|null $io
     * @return Gate|IO|null
     * @throws LogicException
     */
    public function resume(?IO $io = null): Gate|IO|null
    {
        if ($this->done()) {
            throw new LogicException('Process already completed');
        }

        return $this->handle($io);
    }

    private function makeObservation(object $subject): void
    {
        $this->observers?->observe($subject);
    }

    public function __sleep(): array
    {
        $this->serialize = true;
        $this->cleanUp();
        return ['position', 'taskCount', 'tasks'];
    }

    public function __wakeup(): void
    {
        $this->serialize = false;
        // Re-bind kernels if container is ready
        if (Container::isReady()) {
            $this->events = Container::get(EventKernel::class);
            $this->observers = Container::get(ObserverKernel::class);
        }
    }
}
