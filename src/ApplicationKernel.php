<?php

declare(strict_types=1);

namespace Flows;

use Collectibles\Collection;
use Collectibles\Contracts\IO;
use Flows\Container\Container;
use Flows\Contracts\Gate;
use Flows\Event\Kernel as EventKernel;
use Flows\Gates\OffloadOrGate;
use Flows\Gates\OrGate;
use Flows\Gates\XorGate;
use Flows\Observer\Kernel as ObserverKernel;
use Flows\Processes\Internal\BootProcess;
use Flows\Processes\Internal\IO\OffloadedIO;
use Flows\Processes\Internal\OffloadProcess;
use Flows\Processes\Process;
use Flows\Registries\ProcessRegistry;
use LogicException;
use SplStack;

class ApplicationKernel
{
    private ProcessRegistry $processes;
    private EventKernel $events;
    private ObserverKernel $observers;
    private array $completedProcesses = [];
    private SplStack $stack;

    private function flow(): ?IO
    {
        $collection = new Collection();
        $end = false;
        do {
            [$process, $io, $source] = $this->stack->pop();
            $taskKey = $process->getCurrentTaskKey();
            $gateOrReturn = 0 === $taskKey
                ? $process->process($io)
                : $process->resume($collection->get(spl_object_id($source)));

            if ($gateOrReturn instanceof XorGate) {
                $process->cleanUp();
                $this->completedProcesses[] = get_class($process);
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
                $collection->add(
                    $processesReturn,
                    spl_object_id($source)
                );
                // Resume later
            } elseif ($gateOrReturn instanceof OrGate) {
                // This process is interrupted (it started with some other input), will resume later
                $this->stack->push([$process, null, $source]);
                // These processes will run now, as if run in a parallel manner, all with the same input
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
                        (string)spl_object_id($source)
                    );
                } else {
                    // No gate, nowhere to go, end the flow
                    $end = true;
                }

                $this->completedProcesses[] = get_class($process);
                $process->cleanUp();
            }
        } while (!$end);

        return $gateOrReturn;
    }

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

        return $this->flow();
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
     * @param Process $process
     * @param Gate|IO|null $gateOrReturn
     * @return Gate|IO|null
     * @throws LogicException
     */
    // private function processGateOrReturn(
    //     Process $process,
    //     Gate|IO|null $gateOrReturn
    // ): Gate|IO|null {
    //     if ($gateOrReturn instanceof XorGate) {
    //         $this->completedProcesses[] = $process;
    //         return $this->processProcess(
    //             $gateOrReturn(),
    //             $gateOrReturn->getIO()
    //         );
    //     } elseif ($gateOrReturn instanceof OffloadOrGate) {
    //         $offloadOrProcesses = $gateOrReturn();
    //         if (empty($offloadOrProcesses)) {
    //             throw new LogicException('Empty return from gate ' . get_class($gateOrReturn));
    //         }
    //         $offloadProcess = new OffloadProcess();
    //         $processesReturn = $offloadProcess->process(
    //             new OffloadedIO($offloadOrProcesses, $gateOrReturn->getIO())
    //         );
    //         $offloadProcess->cleanUp();
    //         $this->resumeProcess(
    //             $process,
    //             $processesReturn
    //         );
    //         return $processesReturn;
    //     } elseif ($gateOrReturn instanceof OrGate) {
    //         $orProcesses = $gateOrReturn();
    //         if (empty($orProcesses)) {
    //             throw new LogicException('Empty return from gate ' . get_class($gateOrReturn));
    //         }

    //         $orGateIo = new Collection(IO::class);
    //         foreach ($orProcesses as $orProcess) {
    //             $orGateIo->add(
    //                 $this->processProcess(
    //                     $orProcess,
    //                     $gateOrReturn->getIO()
    //                 ),
    //                 $orProcess
    //             );
    //         }
    //         $this->resumeProcess(
    //             $process,
    //             $orGateIo
    //         );
    //         return $orGateIo;
    //     }

    //     $this->completedProcesses[] = $process;
    //     return $gateOrReturn;
    // }

    /**
     *
     * @param string $classNameProcess
     * @param IO|null $io
     * @return Gate|IO|null
     */
    // public function processProcess(
    //     string $classNameProcess,
    //     ?IO $io = null
    // ): Gate|IO|null {
    //     if (!$this->running) {
    //         $this->running = !$this->running;
    //     }

    //     $process = $this->processes->getNamed($classNameProcess);
    //     if (!$this->firstProcess) {
    //         $this->firstProcess = $classNameProcess;
    //     }

    //     $gateOrReturn = $this->processGateOrReturn(
    //         $process,
    //         $process->process($io)
    //     );
    //     $process->cleanUp();
    //     // have we reached the last process?
    //     if ($classNameProcess === $this->firstProcess) {
    //         // yes (recursion)
    //         if ($this->running) {
    //             $this->running = !$this->running;
    //         }

    //         $this->events->handleDeferFromFlow();
    //         $this->observers->handleDeferFromFlow();
    //     }

    //     return $gateOrReturn;
    // }

    /**
     *
     * @param Process $process
     * @param Collection $io
     * @return Gate|IO|null
     */
    // private function resumeProcess(
    //     Process $process,
    //     Collection $io
    // ): Gate|IO|null {
    //     return $this->processGateOrReturn(
    //         $process,
    //         $process->resume($io)
    //     );
    // }

    /**
     * 
     * Current process is offloaded?
     * 
     * @return bool TRUE if process is offloaded, FALSE otherwise
     */
    public static function isOffloadedProcess(): bool
    {
        return defined('OFFLOADED_PROCESS');
    }
}
