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

use Flow\Task\Set;
use RuntimeException;

class Registry {

    private array $taskSets = [];
    private Set $current;

    /**
     * 
     * @param Set $taskSet
     * @return self
     */
    public function add(Set $taskSet): self {
        $this->taskSets[get_class($taskSet)] = $taskSet;
        return $this;
    }
    
    /**
     * 
     * @return Set
     */
    public function getCurrentTaskSet(): Set {
        return $this->current;
    }

    /**
     * 
     * @param string $classNameTaskSet
     * @return Set
     * @throws RuntimeException
     */
    public function getNamed(string $classNameTaskSet): Set {
        if ($this->exists($classNameTaskSet)) {
            return $this->current = $this->taskSets[$classNameTaskSet];
        }

        throw new RuntimeException('Unregistered task set ' . $classNameTaskSet);
    }

    /**
     * 
     * @param string $classNameTaskSet
     * @return bool
     */
    public function exists(string $classNameTaskSet): bool {
        return array_key_exists(
                $classNameTaskSet,
                $this->taskSets
        );
    }
}
