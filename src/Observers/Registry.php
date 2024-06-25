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

namespace Flow\Observers;

use Collectibles\Contracts\IO;
use Flow\Contracts\Observer;
use Flow\Contracts\Gate;
use LogicException;
use ReflectionClass;

class Registry {

    private array $observerOf = [];
    private array $errors = [
        'Subject class %s must implement one of : %s | %s'
    ];

    /**
     * 
     * @param Observer $observer
     * @param string $subject
     * @return self
     * @throws LogicException
     */
    public function register(
            Observer $observer,
            string $subject
    ): self {
        if (!$this->isValid($subject, true)) {
            throw new LogicException(
                sprintf(
                    $this->errors[0],
                    $subject,
                    IO::class,
                    Gate::class
                )
            );
        }
        if (!array_key_exists($subject, $this->observers)) {
            $this->observerOf[$subject] = [];
        }

        $this->observerOf[$subject][] = $observer;
    }

    /**
     * 
     * @param IO|Gate $subject
     * @return void
     */
    public function notify(IO|Gate $subject): void {
        foreach ($this->observerOf[get_class($subject)] as $observer) {
            $observer->observe($subject);
        }
    }

    /**
     * 
     * @param string $instanceOrFQDN
     * @param bool $observerOrSubject
     * @return bool
     */
    private function isValid(
            string|object $instanceOrFQDN,
            bool $observerOrSubject
    ): bool {
        $reflection = new ReflectionClass($instanceOrFQDN);

        return $observerOrSubject 
                ? $reflection->implementsInterface(Gate::class) || $class->implementsInterface(IO::class) 
                : $reflection->implementsInterface(Observer::class);
    }
}
