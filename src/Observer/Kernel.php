<?php

declare(strict_types=1);

namespace Flows\Observer;

use Collectibles\Collection;
use Flows\Attributes\Defer\DeferFromFlow;
use Flows\Attributes\Defer\DeferFromProcess;
use Flows\Attributes\Realtime;
use Flows\Contracts\Observer;
use Flows\Factory;

class Kernel implements Observer
{
    public function __construct(
        private Collection $registry = new Collection(),
        private Collection $deferFromFlow = new Collection(),
        private Collection $deferFromProcess = new Collection()
    ) {
    }

    /**
     *
     * Register an observation, its handler and the timing to handle it
     *
     * @param string $nsSubject The namespaced event class
     * @param string $nsObserver The namespaced event handler class
     * @param DeferFromFlow|DeferFromProcess|Realtime $timing Set if handling is to happen after the process or flow is complete or realtime
     */
    public function register(
        string $nsSubject,
        string $nsObserver,
        DeferFromFlow|DeferFromProcess|Realtime $timing
    ): self {
        $this->registry->set([$nsObserver, $timing], $nsSubject);
        return $this;
    }

    /**
     *
     * Make an observation or store the subject for later observation
     *
     * @param Event $event
     */
    public function observe(object $subject): void
    {
        $nsSubject = get_class($subject);
        if (!$this->registry->has($nsSubject)) {
            return;
        }

        list($nsObserver, $timing) = $this->registry->get($nsSubject);
        if ($timing instanceof Realtime) {
            $observer = Factory::getClassInstance($nsObserver, __CLASS__);
            $observer->observe($subject);
            return;
        }

        $timing instanceof DeferFromFlow
            ? $this->deferFromFlow->add($subject, $nsObserver)
            : $this->deferFromProcess->add($subject, $nsObserver);
    }

    /**
     *
     * Make observations at the end of the work flow
     */
    public function handleDeferFromFlow(): void
    {
        foreach ($this->deferFromFlow->getAll() as $nsObserver => $subjects) {
            $observer = Factory::getClassInstance($nsObserver, __CLASS__);
            // Filter out subject entries that might exist already
            if (is_array($subjects)) {
                foreach (array_unique($subjects, SORT_REGULAR) as $subject) {
                    $observer->observe($subject);
                }
            } else {
                $observer->observe($subjects);
            }
        }
        $this->deferFromFlow = new Collection();
    }

    /**
     *
     * Make observations at the end of the process
     */
    public function handleDeferFromProcess(): void
    {
        foreach ($this->deferFromProcess->getAll() as $nsObserver => $subjects) {
            $observer = Factory::getClassInstance($nsObserver, __CLASS__);
            // Filter out subject entries that might exist already
            if (is_array($subjects)) {
                foreach (array_unique($subjects, SORT_REGULAR) as $subject) {
                    $observer->observe($subject);
                }
            } else {
                $observer->observe($subjects);
            }
        }
        $this->deferFromProcess = new Collection();
    }
}
