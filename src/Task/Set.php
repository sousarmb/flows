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

namespace Flow\Task;

use Flow\Gates\OrGate;
use Flow\Gates\XorGate;
use Flow\Contracts\Gate;
use Flow\Contracts\Task;
use Collectibles\Collection;
use Collectibles\Contracts\IO;
use LogicException;

abstract class Set {

    protected array $tasks = [];
    protected array $errors = [
        'Invalid task set',
        'This task set is done',
        'Resume only after OrGate instance'
    ];

    /**
     * 
     * @throws LogicException
     */
    public function __construct() {
        if (empty($this->tasks) || in_array(
                        false,
                        array_map(
                                fn($task) => $task instanceof Task || $task instanceof Gate,
                                $this->tasks
                        ),
                        true
                )
        ) {
            throw new LogicException($this->errors[0]);
        }
    }

    /**
     * 
     * @return void
     */
    public function cleanUp(): void {
        for ($i = count($this->tasks) - 1; $i >= 0; $i--) {
            $this->tasks[$i]->cleanUp();
        }
    }

    /**
     * 
     * @return bool
     */
    public function done(): bool {
        return key($this->tasks) === null;
    }

    /**
     * 
     * @return int|null
     */
    public function getCurrentTaskKey(): ?int {
        return key($this->tasks);
    }

    /**
     * 
     * @param IO|null $io
     * @return Gate|IO|null
     * @throws LogicException
     */
    public function process(?IO $io = null): Gate|IO|null {
        if ($this->done()) {
            throw new LogicException($this->errors[1]);
        }

        $current = reset($this->tasks);
        do {
            if ($current instanceof XorGate) {
                end($this->tasks);
                return $current->setIO($io);
            } elseif ($current instanceof OrGate) {
                return $current->setIO($io);
            } else {
                $io = $current($io);
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
    public function resume(?Collection $io = null): Gate|IO|null {
        if ($this->done()) {
            throw new LogicException($this->errors[1]);
        } elseif (!(current($this->tasks) instanceof OrGate)) {
            throw new LogicException($this->errors[2]);
        }

        $current = next($this->tasks); // watch out: we may be out of bounds!
        do {
            if ($current instanceof XorGate) {
                end($this->tasks);
                return $current->setIO($io);
            } elseif ($current instanceof OrGate) {
                return $current->setIO($io);
            } elseif ($current) {
                // ... but if not, process the task
                $io = $current($io);
            }
        } while ($current = next($this->tasks));

        return $io;
    }
}
