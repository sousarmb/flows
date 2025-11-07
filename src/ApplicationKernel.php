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

class ApplicationKernel
{
    private ProcessRegistry $processes;
    private EventKernel $events;
    private ObserverKernel $observers;
    private array $completedProcesses = [];
    private ?string $firstProcess = null;
    private bool $running = false;

    /**
     *
     * Boot the application (load config, create and populate the service container, ...)
     */
    public function __construct()
    {
        $process = new BootProcess();
        $io = $this->processGateOrReturn(
            $process,
            $process->process(null)
        );
        $process->cleanUp();
        $container = $io->get(Container::class);
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
    private function processGateOrReturn(
        Process $process,
        Gate|IO|null $gateOrReturn
    ): Gate|IO|null {
        if ($gateOrReturn instanceof XorGate) {
            $this->completedProcesses[] = $process;
            return $this->processProcess(
                $gateOrReturn(),
                $gateOrReturn->getIO()
            );
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
            $this->resumeProcess(
                $process,
                $processesReturn
            );
            return $processesReturn;
        } elseif ($gateOrReturn instanceof OrGate) {
            $orProcesses = $gateOrReturn();
            if (empty($orProcesses)) {
                throw new LogicException('Empty return from gate ' . get_class($gateOrReturn));
            }

            $orGateIo = new Collection(IO::class);
            foreach ($orProcesses as $orProcess) {
                $orGateIo->add(
                    $this->processProcess(
                        $orProcess,
                        $gateOrReturn->getIO()
                    ),
                    $orProcess
                );
            }
            $this->resumeProcess(
                $process,
                $orGateIo
            );
            return $orGateIo;
        }

        $this->completedProcesses[] = $process;
        return $gateOrReturn;
    }

    /**
     *
     * @param string $classNameProcess
     * @param IO|null $io
     * @return Gate|IO|null
     */
    public function processProcess(
        string $classNameProcess,
        ?IO $io = null
    ): Gate|IO|null {
        if (!$this->running) {
            $this->running = !$this->running;
        }

        $process = $this->processes->getNamed($classNameProcess);
        if (!$this->firstProcess) {
            $this->firstProcess = $classNameProcess;
        }

        $gateOrReturn = $this->processGateOrReturn(
            $process,
            $process->process($io)
        );
        $process->cleanUp();
        // have we reached the last process?
        if ($classNameProcess === $this->firstProcess) {
            // yes (recursion)
            if ($this->running) {
                $this->running = !$this->running;
            }

            $this->events->handleDeferFromFlow();
            $this->observers->handleDeferFromFlow();
        }

        return $gateOrReturn;
    }

    /**
     *
     * @param Process $process
     * @param Collection $io
     * @return Gate|IO|null
     */
    private function resumeProcess(
        Process $process,
        Collection $io
    ): Gate|IO|null {
        return $this->processGateOrReturn(
            $process,
            $process->resume($io)
        );
    }

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
