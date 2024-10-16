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

namespace Flow;

use LogicException;
use Collectibles\Collection;
use Collectibles\Contracts\IO;
use Flow\Task\Registry as TaskRegistry;
use Flow\Task\Set;
use Flow\Gates\XorGate;
use Flow\Gates\OrGate;
use Flow\Contracts\Gate;
use Flow\Observers\Registry as ObserverRegistry;

class Kernel {

    private array $completeTaskSets = [];
    private array $errors = [
        'Inclusive (OR) gates must return at least 1 or more set of tasks to follow'
    ];
    private ?string $firstTaskSet = null;
    private bool $running = false;

    /**
     * 
     * @param Registry $taskSets
     */
    public function __construct(
            private TaskRegistry $taskSets,
            private ?ObserverRegistry $observers = null
    ) {}

    /**
     * 
     * @return array
     */
    public function getProcessedTaskSetsFlow(): array {
        return $this->completeTaskSets;
    }

    /**
     * 
     * @param Set $taskSet
     * @param Gate|IO|null $gateOrReturn
     * @return Gate|IO|null
     * @throws LogicException
     */
    private function processGateOrReturn(
            Set $taskSet,
            Gate|IO|null $gateOrReturn
    ): Gate|IO|null {
        if ($this->observers) {
            $this->observers->notify(
                    $gateOrReturn instanceof Gate ? $gateOrReturn->getIO() : $gateOrReturn
            );
        }
        if ($gateOrReturn instanceof XorGate) {
            $this->completeTaskSets[] = $taskSet;
            return $this->processTaskSet(
                            $gateOrReturn(),
                            $gateOrReturn->getIO()
            );
        } elseif ($gateOrReturn instanceof OrGate) {
            $orTaskSets = $gateOrReturn();
            if (empty($orTaskSets)) {
                throw new LogicException($this->errors[0]);
            }

            $orGateIo = new Collection(IO::class);
            foreach ($orTaskSets as $orTaskSet) {
                $orGateIo->set(
                        $this->processTaskSet(
                                $orTaskSet,
                                $gateOrReturn->getIO()
                        )
                );
            }
            $this->resumeTaskSet(
                    $taskSet,
                    $orGateIo
            );
            return $orGateIo;
        }

        $this->completeTaskSets[] = $taskSet;
        return $gateOrReturn;
    }

    /**
     * 
     * @param string $classNameTaskSet
     * @param IO|null $io
     * @return Gate|IO|null
     */
    public function processTaskSet(
            string $classNameTaskSet,
            ?IO $io = null
    ): Gate|IO|null {
        if (!$this->running) {
            $this->running = !$this->running;
        }
        $taskSet = $this->taskSets->getNamed($classNameTaskSet);
        if (!$this->firstTaskSet) {
            $this->firstTaskSet = $classNameTaskSet;
        }

        $gateOrReturn = $this->processGateOrReturn(
                $taskSet,
                $taskSet->process($io)
        );
        $taskSet->cleanUp();
        // have we reached the last task set?
        if ($classNameTaskSet === $this->firstTaskSet) {
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
     * @param Set $taskSet
     * @param Collection $io
     * @return Gate|IO|null
     */
    private function resumeTaskSet(
            Set $taskSet,
            Collection $io
    ): Gate|IO|null {
        return $this->processGateOrReturn(
                        $taskSet,
                        $taskSet->resume($io)
        );
    }
}
