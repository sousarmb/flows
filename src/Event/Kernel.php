<?php

declare(strict_types=1);

namespace Flows\Event;

use Collectibles\Collection;
use Flows\Attributes\Defer\DeferFromFlow;
use Flows\Attributes\Defer\DeferFromProcess;
use Flows\Attributes\Realtime;
use Flows\Contracts\EventHandler;
use Flows\Factory;
use LogicException;

class Kernel implements EventHandler
{
    public function __construct(
        private Collection $registry = new Collection(),
        private Collection $deferFromFlow = new Collection(),
        private Collection $deferFromProcess = new Collection()
    ) {
    }

    /**
     *
     * Register an event, its handler and the timing to handle it
     *
     * @param string $nsEvent The namespaced event class
     * @param string $nsHandler The namespaced event handler class
     * @param DeferFromFlow|DeferFromProcess|Realtime $timing Set if handling is to happen after the process or flow is complete or realtime
     * @return self
     */
    public function register(
        string $nsEvent,
        string $nsHandler,
        DeferFromFlow|DeferFromProcess|Realtime $timing
    ): self {
        $this->registry->set([$nsHandler, $timing], $nsEvent);
        return $this;
    }

    /**
     *
     * Handle event classes, using information from registered event handlers
     *
     * @param Event $event
     */
    public function handle(Event $event): void
    {
        $nsEvent = get_class($event);
        if (!$this->registry->has($nsEvent)) {
            throw new LogicException("No handler defined for event $nsEvent");
        }

        list($nsHandler, $timing) = $this->registry->get($nsEvent);
        if ($timing instanceof Realtime) {
            $handler = Factory::getClassInstance($nsHandler);
            $handler->handle($event);
            return;
        }

        $timing instanceof DeferFromFlow
            ? $this->deferFromFlow->add($event, $nsHandler)
            : $this->deferFromProcess->add($event, $nsHandler);
    }

    public function handleDeferFromFlow(): void
    {
        foreach ($this->deferFromFlow->getAll() as $nsHandler => $events) {
            $handler = Factory::getClassInstance($nsHandler);
            if (is_array($events)) {
                foreach (array_unique($events, SORT_REGULAR) as $event) {
                    $handler->handle($event);
                }
            } else {
                $handler->handle($events);
            }
        }
        $this->deferFromProcess = new Collection();
    }

    public function handleDeferFromProcess(): void
    {
        foreach ($this->deferFromProcess->getAll() as $nsHandler => $events) {
            $handler = Factory::getClassInstance($nsHandler);
            if (is_array($events)) {
                foreach (array_unique($events, SORT_REGULAR) as $event) {
                    $handler->handle($event);
                }
            } else {
                $handler->handle($events);
            }
        }
        $this->deferFromProcess = new Collection();
    }
}
