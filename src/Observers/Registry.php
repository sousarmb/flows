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

namespace Flows\Observers;

use Collectibles\Contracts\IO;
use Flows\Contracts\Deferred;
use Flows\Contracts\Gate;
use Flows\Contracts\Observer;
use LogicException;
use ReflectionClass;
use SplQueue;

class Registry
{

    private array $deferred = [
        '*' => []
    ];
    private array $deferredSubjects = [];
    private array $realtime = [
        '*' => []
    ];
    private array $errors = [
        'Subject class %s must implement one of : %s | %s'
    ];
    private bool $hasDeferredAllObserver = false;
    private bool $hasRealTimeAllObserver = false;

    public function __construct()
    {
        $this->deferredSubjects['*'] = new SplQueue();
    }

    /**
     *
     * @param Deferred|Observer $observer
     * @param string $nsSubjectClass
     * @return self
     * @throws LogicException
     */
    public function register(
        Deferred|Observer $observer,
        string $nsSubjectClass
    ): self {
        $observersKey = $observer instanceof Deferred ? 'deferred' : 'realtime';
        if ($nsSubjectClass == '*') {
            // flags to prevent array count()s later
            if ($observersKey == 'deferred') {
                $this->hasDeferredAllObserver = true;
            } else {
                $this->hasRealTimeAllObserver = true;
            }
        } elseif ($this->isValidSubject($nsSubjectClass)) {
            if (!isset(
                $nsSubjectClass,
                $this->{$observersKey}
            )) {
                $this->{$observersKey}[$nsSubjectClass] = [];
            }
        } else {
            throw new LogicException(
                sprintf(
                    $this->errors[0],
                    $nsSubjectClass,
                    IO::class,
                    Gate::class
                )
            );
        }

        $this->{$observersKey}[$nsSubjectClass][] = $observer;

        return $this;
    }

    /**
     * 
     * @param IO|Gate $subject
     * @return void
     */
    public function notify(IO|Gate $subject): void
    {
        $nsSubjectClass = get_class($subject);
        // check if there are any observers of this subject
        if (isset($this->realtime[$nsSubjectClass])) {
            // notify real-time observers of this subject
            foreach ($this->realtime[$nsSubjectClass] as $observers) {
                foreach ($observers as $observer) {
                    $observer($subject);
                }
            }
        }
        if ($this->hasRealTimeAllObserver) {
            // notify real-time observers for all subjects
            foreach ($this->realtime['*'] as $observer) {
                $observer($subject);
            }
        }
        if (isset($this->deferred[$nsSubjectClass])) {
            // there are, store the subject to notify observers later
            if (!isset($this->deferredSubjects[$nsSubjectClass])) {
                $this->deferredSubjects[$nsSubjectClass] = new SplQueue();
            } elseif ($this->isDeferredSubjectRegistered(
                $nsSubjectClass,
                $subject
            )) {
                // prevent duplicate subject registration
                return;
            }

            $this->deferredSubjects[$nsSubjectClass][] = $subject;
        } elseif ($this->hasDeferredAllObserver) {
            // and this is in case there any deferred "observe all subjects"
            // observers
            if ($this->isDeferredSubjectRegistered(
                '*',
                $subject
            )) {
                // prevent duplicate subject registration
                return;
            }

            $this->deferredSubjects['*'][] = $subject;
        }
    }

    /**
     * 
     * @return void
     */
    public function notifyDeferred(): void
    {
        foreach ($this->deferredSubjects as $nsSubjectClass => $subjects) {
            foreach ($subjects as $subject) {
                foreach ($this->deferred[$nsSubjectClass] as $observer) {
                    $observer($subject);
                }
            }
        }
    }

    /**
     *
     * @param string $nsSubjectClass
     * @return bool
     */
    private function isValidSubject(string $nsSubjectClass): bool
    {
        $reflection = new ReflectionClass($nsSubjectClass);

        return $reflection->implementsInterface(Gate::class) || $reflection->implementsInterface(IO::class);
    }

    /**
     * 
     * @param string $nsSubjectClass
     * @param object $subject
     * @return bool
     */
    private function isDeferredSubjectRegistered(
        string $nsSubjectClass,
        object $subject
    ): bool {
        $this->deferredSubjects[$nsSubjectClass]->rewind();
        do {
            if ($this->deferredSubjects[$nsSubjectClass]->current() === $subject) {
                return true;
            }

            $this->deferredSubjects[$nsSubjectClass]->next();
        } while ($this->deferredSubjects[$nsSubjectClass]->valid());

        return false;
    }
}
