<?php

/*
 * The MIT License
 *
 * Copyright 2024 rsousa <rmbsousa@gmail.com>.
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */

declare(strict_types=1);

namespace Flows;

use Collectibles\Collection;
use Collectibles\Contracts\IO;
use Flows\Contracts\Gate;
use Flows\Gates\OffloadOrGate;
use Flows\Gates\OrGate;
use Flows\Gates\XorGate;
use Flows\Observers\Registry as ObserverRegistry;
use Flows\Processes\Internal\IO\OffloadedIO;
use Flows\Processes\Internal\OffloadProcess;
use Flows\Processes\Process;
use Flows\Processes\Registry as ProcessRegistry;
use LogicException;

class Kernel
{

    private array $completedProcesses = [];
    private array $errors = [
        'Inclusive (OR) gates must return at least 1 or more set of processes to follow'
    ];
    private ?string $firstProcess = null;
    private bool $running = false;

    /**
     * 
     * @param ProcessRegistry $processes
     */
    public function __construct(
        private ProcessRegistry $processes,
        private ?ObserverRegistry $observers = null
    ) {}

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
        if ($this->observers) {
            $this->observers->notify(
                $gateOrReturn instanceof Gate ? $gateOrReturn->getIO() : $gateOrReturn
            );
        }
        if ($gateOrReturn instanceof XorGate) {
            $this->completedProcesses[] = $process;
            return $this->processProcess(
                $gateOrReturn(),
                $gateOrReturn->getIO()
            );
        } elseif ($gateOrReturn instanceof OffloadOrGate) {
            $offloadOrProcesses = $gateOrReturn();
            if (empty($offloadOrProcesses)) {
                throw new LogicException($this->errors[0]);
            }

            $offloadProcess = new OffloadProcess();
            $processesReturn = $offloadProcess->process(
                new OffloadedIO($offloadOrProcesses, $gateOrReturn->getIO())
            );
            $this->resumeProcess(
                $process,
                $processesReturn
            );
            return $processesReturn;
        } elseif ($gateOrReturn instanceof OrGate) {
            $orProcesses = $gateOrReturn();
            if (empty($orProcesses)) {
                throw new LogicException($this->errors[0]);
            }

            $orGateIo = new Collection(IO::class);
            foreach ($orProcesses as $orProcess) {
                $orGateIo->set(
                    $this->processProcess(
                        $orProcess,
                        $gateOrReturn->getIO()
                    )
                );
            }
            $this->resumeProcess(
                $$process,
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
            if ($this->observers) {
                $this->observers->notifyDeferred();
            }
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
}
