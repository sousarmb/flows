<?php

declare(strict_types=1);

namespace Flows;

use Collectibles\Collection;
use Collectibles\Contracts\IO;
use Flows\Container\Container;
use Flows\Event\Kernel as EventKernel;
use Flows\Facades\Config;
use Flows\Facades\Logger;
use Flows\Gates\OffloadOrGate;
use Flows\Gates\OrGate;
use Flows\Gates\XorGate;
use Flows\Observer\Kernel as ObserverKernel;
use Flows\Processes\Internal\BootProcess;
use Flows\Processes\Internal\Events\ApplicationFullStop;
use Flows\Processes\Internal\IO\OffloadedIO;
use Flows\Processes\Internal\OffloadProcess;
use Flows\Registries\ProcessRegistry;
use LogicException;
use SplStack;

class ApplicationKernel
{
    private bool $booted = false;
    private ProcessRegistry $processes;
    private EventKernel $events;
    private ObserverKernel $observers;
    private array $completedProcesses = [];
    private SplStack $stack;
    private static bool $fullStop;

    private function flow(): ?IO
    {
        $collection = new Collection();
        $end = false;
        if ($this->booted) {
            $keepOutput = Config::getApplicationSettings()->get('gate.io.keep', false);
        }

        do {
            if (isset(static::$fullStop)) {
                $nsProcess = get_class($process);
                $taskKey = $process->getCurrentTaskKey();
                $this->events->handle(
                    new ApplicationFullStop($nsProcess, $taskKey)
                );
                Logger::info("Full stop called {$nsProcess} task key {$taskKey}");
                return null;
            }

            [$process, $io, $source] = $this->stack->pop();
            $taskKey = $process->getCurrentTaskKey();
            if (0 === $taskKey) {
                // process from new
                $gateOrReturn = $process->process($io);
            } else {
                // resume process
                $nsProcess = get_class($process);
                $gateOrReturn = $process->resume(
                    // with fresh input (output from previous process(es)
                    $collection->getAsCollection($nsProcess)
                );
                if (!$keepOutput) {
                    $collection->delete($nsProcess);
                }
            }
            if ($gateOrReturn instanceof XorGate) {
                $process->cleanUp();
                if (!static::isOffloadedProcess()) {
                    // Only one process in offloaded processes, no need for extra work
                    $this->completedProcesses[] = $process;
                }
                // Move to the next process
                $this->stack->push([
                    $this->processes->getNamed($gateOrReturn()),
                    // With previous process output
                    $gateOrReturn->getIO(),
                    // And no source process, nothing to resume, just go forward to next process
                    null
                ]);
            } elseif ($gateOrReturn instanceof OffloadOrGate) {
                $offloadOrProcesses = $gateOrReturn();
                if (empty($offloadOrProcesses)) {
                    throw new LogicException('Empty return from gate ' . get_class($gateOrReturn));
                }
                $offloadProcess = new OffloadProcess();
                $processesReturn = $offloadProcess->process(
                    new OffloadedIO($offloadOrProcesses, $gateOrReturn->getIO())
                );
                $offloadProcess->cleanUp();
                // Resume process
                $collection->add(
                    $processesReturn,
                    get_class($process)
                );
                // Resume next loop
                $this->stack->push([$process, null, $source]);
            } elseif ($gateOrReturn instanceof OrGate) {
                // This process is interrupted (it started with some other input), will resume later
                $this->stack->push([$process, null, $source]);
                // These processes will run next, as if run in a parallel manner, all with the same input
                foreach ($gateOrReturn() as $nsProcess) {
                    $this->stack->push([
                        $this->processes->getNamed($nsProcess),
                        $gateOrReturn->getIO(),
                        // ... With source process, must resume from here
                        $process
                    ]);
                }
            } else {
                if ($source) {
                    $collection->add(
                        $gateOrReturn,
                        get_class($source) . '.' . get_class($process)
                    );
                } else {
                    // No gate, nowhere to go, end the flow
                    $end = true;
                }

                $process->cleanUp();
                if (!static::isOffloadedProcess()) {
                    // Only one process in offloaded processes, no need for extra work
                    $this->completedProcesses[] = $process;
                }
            }
        } while (!$end);

        return $gateOrReturn;
    }

    /**
     * 
     * Process the flow
     * 
     * @param string $nsInitialProcess Start with this process
     * @param ?IO $io Give it this input
     * @return IO|null
     */
    public function process(
        string $nsInitialProcess,
        ?IO $io = null
    ): ?IO {
        $this->stack->push([
            // Start flow with this process
            $this->processes->getNamed($nsInitialProcess),
            // ... and this input
            $io,
            // ... this is the first process, nothing to resume
            null
        ]);
        // Do the actual work
        $output = $this->flow();
        // Handle remaining stuff after the work is done
        $this->events->handleDeferFromFlow();
        $this->observers->handleDeferFromFlow();
        return $output;
    }

    /**
     *
     * Boot the application (load config, create and populate the service container, ...)
     */
    public function __construct()
    {
        $this->stack = new SplStack();
        $this->stack->push([
            new BootProcess(),
            null,
            null
        ]);
        $output = $this->flow();
        $container = $output->get(Container::class);
        $this->events = $container->get(EventKernel::class);
        $this->observers = $container->get(ObserverKernel::class);
        $this->booted = true;
    }

    /**
     *
     * Set the set of processes that the kernel will handle
     *
     * @param ProcessRegistry $registry
     */
    public function setProcessRegistry(ProcessRegistry $registry): void
    {
        $this->processes = $registry;
    }

    /**
     *
     * @return array
     */
    public function getCompletedProcesses(): array
    {
        return $this->completedProcesses;
    }

    /**
     * 
     * Running process outside of main process (offloaded)?
     * 
     * @return bool TRUE if process is offloaded, FALSE otherwise
     */
    public static function isOffloadedProcess(): bool
    {
        return defined('OFFLOADED_PROCESS');
    }

    /**
     * 
     * Stop all work on next iteration
     */
    public static function fullStop(): void
    {
        static::$fullStop = true;
    }
}
