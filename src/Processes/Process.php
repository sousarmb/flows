<?php

declare(strict_types=1);

namespace Flows\Processes;

use Collectibles\Contracts\IO as IOContract;
use Flows\Contracts\Gate as GateContract;
use Flows\Contracts\Tasks\CleanUp as CleanUpContract;
use Flows\Contracts\Tasks\Task as TaskContract;
use Flows\Event\Kernel as EventKernel;
use Flows\Facades\Container;
use Flows\Facades\Logger;
use Flows\Factory;
use Flows\Gates\OrGate;
use Flows\Gates\XorGate;
use Flows\Observer\Kernel as ObserverKernel;
use Flows\Processes\Signal\SaveState;
use Flows\Processes\Signal\UndoState;
use LogicException;
use RuntimeException;
use SplStack;

abstract class Process implements CleanUpContract
{
    private int $position = 0;
    private readonly int $taskCount;
    protected array $tasks = [];
    private ?EventKernel $events = null;
    private ?ObserverKernel $observers = null;

    /**
     *
     * @throws LogicException
     */
    public function __construct(
        private readonly SplStack $state = new SplStack()
    ) {
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
            if ($this->invalidProcessMember($task)) {
                throw new LogicException('Invalid task: must be instance of Task or Gate contract');
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
    public function cleanUp(bool $forSerialization = false): void
    {
        $this->events?->handleDeferFromProcess();
        $this->observers?->observe($this);
        $this->observers?->handleDeferFromProcess();
        for ($i = $this->taskCount - 1; $i >= 0; $i--) {
            // Propagate signal to tasks
            if (!$this->tasks[$i] instanceof SaveState) {
                $this->tasks[$i]->cleanUp($forSerialization);
            }
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
     * @param IOContract|null $io
     * @return GateContract|IOContract|null
     * @throws RuntimeException
     */
    private function handle(?IOContract $io = null): GateContract|IOContract|null
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
                // Always advance past OrGate (prevent infinite loop on first task)
                $this->position++;
                return $work;
            } elseif ($work instanceof SaveState) {
                $this->state->push([$this->position, $io]);
            } elseif ($work instanceof UndoState) {
                if ($this->state->count() === 0) {
                    throw new RuntimeException('No savepoint available');
                }

                $undo = $work->setIO($io)();
                $this->makeObservation($work);
                if ($undo > 0) {
                    while ($undo && [$savedPosition, $io] = $this->state->pop()) {
                        $undo--;
                    }
                    $this->position = $savedPosition;
                }

                Logger::info(__CLASS__ . ": undo {$undo}");
            } else {
                // Regular task, do the work
                $io = $work($io);
                $this->makeObservation($io);
            }
            // Keep working
            $this->position++;
        } while ($this->position < $this->taskCount);
        return $io;
    }

    /**
     * 
     * Start from first task
     *
     * @param Collection|null $io
     * @return GateContract|IOContract|null
     * @throws LogicException
     */
    public function run(?IOContract $io = null): GateContract|IOContract|null
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
     * @param IOContract|null $io
     * @return GateContract|IOContract|null
     * @throws LogicException
     */
    public function resume(?IOContract $io = null): GateContract|IOContract|null
    {
        if ($this->done()) {
            throw new LogicException('Process already completed');
        }

        return $this->handle($io);
    }

    private function makeObservation(GateContract|IOContract|UndoState|null $subject): void
    {
        // Null-safe observation
        $subject && $this->observers?->observe($subject);
    }

    /**
     * 
     * Prepare process and tasks for serialization. 
     * Serialize position, task count and tasks members.
     * 
     * @return array<int, string> 
     */
    public function __sleep(): array
    {
        $this->cleanUp(true);
        return ['position', 'taskCount', 'tasks'];
    }

    /**
     * 
     * Re-bind event and observer kernel if container ready.
     * Start savepoint stack.
     */
    public function __wakeup(): void
    {
        // Re-bind kernels if container is ready
        if (Container::isReady()) {
            $this->events = Container::get(EventKernel::class);
            $this->observers = Container::get(ObserverKernel::class);
        }

        $this->state = new SplStack();
    }

    /**
     * 
     * Check if class is allowed as a process task class
     * 
     * @param object $task To assert class
     * @return bool TRUE => valid, FALSE => not valid
     */
    private function invalidProcessMember(object $task): bool
    {
        return !$task instanceof TaskContract
            && !$task instanceof GateContract
            && !$task instanceof SaveState
            && !$task instanceof UndoState;
    }
}
