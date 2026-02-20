<?php

declare(strict_types=1);

namespace Flows\Gates;

use Flows\Contracts\Gates\EventGate as EventGateContract;
use Flows\Contracts\Gates\Frequent as FrequentContract;
use Flows\Contracts\Gates\GateEvent as GateEventContract;
use Flows\Contracts\Gates\HttpEvent as HttpEventContract;
use Flows\Contracts\Gates\Stream as StreamContract;
use Flows\Facades\Logger;
use Flows\Gates\Events\HttpEvent;
use Flows\Gates\Gate;
use Flows\Helpers\Behaviour;
use Flows\Reactor\Reactor;
use Flows\Traits\Echos;
use InvalidArgumentException;
use LogicException;

/**
 * Choose path to take based on events and data
 */
abstract class EventGate extends Gate implements EventGateContract
{
    use Echos;

    /**
     * @var array<int, array<int, GateEventContract, int|null, int|null, Behaviour|null>> Events to be resolved
     */
    private array $events = [];

    /**
     * @var int Wait this many seconds for event resolution, when expired take default process
     */
    protected int $expires = 1;

    /**
     * @var GateEventContract|null The first event to resolve successfully (or null if none)
     */
    protected ?GateEventContract $winner = null;

    /**
     * Add to list of events that trigger gate resolution
     * 
     * @param GateEventContract $event
     * @param int|null $failCount Number of times event resource may fail before triggering fail action (valid for streamed events only)
     * @param Behaviour|null $failAction Gate behaviour if event resource is lost (valid for streamed events only)
     * @return self
     * @throws InvalidArgumentException When invalid behaviour is provided or when fail behaviour is provided for frequent event
     */
    protected function pushEvent(
        GateEventContract $event,
        ?int $failCount = null,
        ?Behaviour $failAction = null
    ): self {
        if ($event instanceof FrequentContract) {
            if (!is_null($failCount) || !is_null($failAction)) {
                throw new InvalidArgumentException('Frequent event fail behaviour is not implemented');
            }
        }

        switch ($failAction) {
            case null:
            case Behaviour::Continue:
            case Behaviour::Exit:
            case Behaviour::Resolve:
                break;
            default:
                throw new InvalidArgumentException("Behaviour not implemented: {$failAction}");
        }
        // [the event class, fail counter, fails allowed, action on counter exhaustion]
        $this->events[] = [$event, $failCount, $failCount, $failAction];
        return $this;
    }

    /**
     * Start reactor to assert conditions set by gate events
     * 
     * @throws LogicException When no events are set to wait on
     */
    public function waitForEvent(): void
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
        foreach ($this->events as $k => $gateEvent) {
            [$event, $failCounter, $maxFails, $failAction] = $gateEvent;
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
                $event instanceof HttpEventContract
                && $event instanceof StreamContract
                && $event instanceof GateEventContract
            ) {
                $reactor->onReadable(
                    $event->getResource(),
                    function ($stream, $reactor) use ($event) {
                        $client = $event->acceptClient();
                        $data = fgets($client);
                        if ($event->resolve($data)) {
                            $reactor->stopRun();
                            $this->winner = $event;
                        }
                    }
                );
                if (!is_null($maxFails)) {
                    // Set heartbeat to check if handler server is responding
                    $reactor->addTimer(
                        1,
                        function ($reactor) use ($k) {
                            $status = $this->pingHandlerServer();
                            $this->takeAction($status, $reactor, $k);
                        },
                        true
                    );
                }
            } elseif (
                $event instanceof StreamContract
                && $event instanceof GateEventContract
            ) {
                stream_set_blocking($event->getResource(), false);
                $reactor->onReadable(
                    $event->getResource(),
                    function ($stream, $reactor) use ($event) {
                        if ($event->resolve($stream)) {
                            $reactor->stopRun();
                            $this->winner = $event;
                        }
                    }
                );
                if (!is_null($maxFails)) {
                    // Set heartbeat to check if stream resource is still available
                    $reactor->addTimer(
                        1,
                        function ($reactor) use ($event, $k) {
                            $meta = stream_get_meta_data($event->getResource());
                            $status = match ($meta['wrapper_type']) {
                                'plainfile' => is_readable($meta['uri']),
                                'file' => is_readable($meta['uri']),
                                'http' => $this->connectWithHost($meta['uri']),
                                default => true, // For other stream types, assume the resource is available.
                            };
                            $this->takeAction($status, $reactor, $k);
                        },
                        true
                    );
                }
            }
        }
        $reactor->run();
    }

    public function cleanUp(bool $forSerialization = false): void
    {
        foreach ($this->events as $gateEvent) {
            [$event, $failCounter, $maxFails, $failAction] = $gateEvent;
            if ($event instanceof StreamContract) {
                $event->closeResource();
            }
        }
    }

    /**
     * Wait for one of many events to resolve or take default path if none does.
     * Use data to help in the path choice.
     * Must call waitForEvent() to start race condition between gate events, winner 
     * event is stored in self::$winner
     * 
     * @return string The chosen path
     */
    abstract public function __invoke(): string;

    /**
     * Get gate time out 
     * 
     * @return int Second(s)
     */
    public function getTimeout(): int
    {
        return $this->expires;
    }

    /**
     * Whether this event gate has any frequent events?
     * 
     * @return bool TRUE => yes, FALSE => no
     */
    public function hasFrequentEvents(): bool
    {
        return (bool)count(array_filter($this->events, fn($gateEvent) => $gateEvent[0] instanceof FrequentContract));
    }

    /**
     * Whether this event gate has any HTTP events?
     * Used by the kernel to start HTTP handler server before gate usage
     * 
     * @return bool TRUE => yes, FALSE => no
     */
    public function hasHttpEvents(): bool
    {
        return (bool)count(array_filter($this->events, fn($gateEvent) => is_subclass_of($gateEvent[0], HttpEvent::class)));
    }

    /**
     * Whether this event gate has any stream events?
     * 
     * @return bool TRUE => yes, FALSE => no
     */
    public function hasStreamEvents(): bool
    {
        return (bool)count(array_filter($this->events, fn($gateEvent) => $gateEvent[0] instanceof StreamContract));
    }

    abstract public function registerEvents(): void;

    private function takeAction(
        bool $status,
        Reactor $reactor,
        int $k
    ): void {
        [$event, &$failCounter, &$maxFails, $failAction] = $this->events[$k];
        if ($failCounter < 0) {
            // Already failed, don't come here again
            return;
        }
        if ($status) {
            // All is well
            if ($failCounter !== $maxFails) {
                Logger::info(
                    'status OK',
                    ['behaviour' => 'reset_counter', 'event' => $event::class, 'instance_uid' => INSTANCE_UID]
                );
                // Reset fail counter
                $failCounter = $maxFails;
            }
            // Keep waiting for event to resolve
            return;
        }
        if ($failCounter > 0) {
            // HTTP handler server not responding, decrease counter and keep waiting 
            $failCounter--;
            return;
        }
        if ($failCounter === 0) {
            // No more waiting, take fail action
            $failCounter--;
            switch ($failAction) {
                case Behaviour::Continue: // Keep waiting for other events to resolve
                    $reactor->remove($event->getResource()); // ... but not this one anymore
                    break;
                case Behaviour::Exit: // Stop waiting for events to resolve and take default path
                    $reactor->stopRun();
                    break;
                case Behaviour::Resolve: // Stop waiting for events to resolve and take this path
                    $this->winner = $event;
                    $reactor->stopRun();
                    break;
            }
            Logger::warning(
                'status NOK',
                ['behaviour' => $failAction->value, 'event' => $event::class, 'instance_uid' => INSTANCE_UID]
            );
        }
    }
}
