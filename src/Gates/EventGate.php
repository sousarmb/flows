<?php

declare(strict_types=1);

namespace Flows\Gates;

use Flows\Contracts\Gates\Frequent as FrequentContract;
use Flows\Contracts\Gates\GateEvent as GateEventContract;
use Flows\Contracts\Gates\PipeListener as PipeListenerContract;
use Flows\Gates\Gate;
use Flows\Reactor\Reactor;
use LogicException;

/**
 * 
 * Choose path to take based on events and data
 */
abstract class EventGate extends Gate
{
    /**
     * @var array<int, GateEvent> Events to be resolved
     */
    protected array $events = [];
    /**
     * @var int Wait this many seconds for event resolution, when expired take default process
     */
    protected int $expires = 1;
    /**
     * @var GateEvent|null The first event to resolve successfully (or null if none)
     */
    protected ?GateEventContract $winner = null;

    /**
     * 
     * Add to list of events that trigger gate resolution
     * 
     * @param GateEventContract $event
     * @return self
     */
    protected function pushEvent(GateEventContract $event): self
    {
        $this->events[] = $event;
        return $this;
    }

    /**
     * 
     * Start reactor to assert conditions set by gate events
     * 
     * @throws LogicException When no events are set to wait on
     */
    protected function waitForEvent(): void
    {
        if ([] === $this->events) {
            throw new LogicException('No events set to wait on');
        }

        $reactor = new Reactor();
        // Gate life time, stop reactor even if no gate event resolves 
        $reactor->addTimer(
            $this->expires,
            function ($reactor) {
                $reactor->stopRun();
            },
            false
        );
        // Register gate events to race each other
        foreach ($this->events as $event) {
            if (
                $event instanceof FrequentContract
                && $event instanceof GateEventContract
            ) {
                $reactor->addTimer(
                    $event->getFrequency(),
                    function ($reactor) use ($event) {
                        if ($event->resolve()) {
                            $reactor->stopRun();
                            $this->winner = $event;
                        }
                    },
                    true
                );
            } elseif (
                $event instanceof PipeListenerContract
                && $event instanceof GateEventContract
            ) {
                stream_set_blocking($event->getPipe(), false);
                $reactor->onReadable(
                    $event->getPipe(),
                    function ($stream, $reactor) use ($event) {
                        if ($event->resolve($stream)) {
                            $reactor->stopRun();
                            $this->winner = $event;
                        }
                    }
                );
            }
        }
        $reactor->run();
    }

    /**
     * 
     * Wait for one of many events to resolve or take default path if none does.
     * Use data to help in the path choice.
     * Must call waitForEvent() to start race condition between gate events, winner 
     * event is stored in self::$winner
     * 
     * @return string The chosen path
     */
    abstract public function __invoke(): string;
}
