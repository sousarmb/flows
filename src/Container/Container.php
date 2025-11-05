<?php

declare(strict_types=1);

namespace Flows\Container;

use Collectibles\Collection;
use Flows\Container\ServiceImplementation\Abstraction;
use Flows\Container\ServiceImplementation\Concrete;
use Flows\Contracts\Container\Entry;
use Flows\Factory;
use Flows\Processes\Internal\BootProcess;
use LogicException;

class Container
{
    private bool $booted = false;

    public function __construct(
        private Collection $services = new Collection(),
        private Collection $providers = new Collection()
    ) {
    }

    /**
     *
     * @param string $nsAbstractionOrConcrete
     * @return object The service instance
     */
    public function get(
        string $nsAbstractionOrConcrete,
        ?string $caller = null
    ): object {
        $entry = $this->providers->get($nsAbstractionOrConcrete);
        if ($entry->isSingleton()) {
            if ($this->services->has($nsAbstractionOrConcrete)) {
                return $this->services->get($nsAbstractionOrConcrete);
            }

            $service = $this->fabricate($entry, $caller);
            $this->services->set($service, $entry->provides());
            return $service;
        }
        // a new instance
        return $this->fabricate($entry, $caller);
    }

    private function fabricate(Entry $entry, ?string $caller = null): object
    {
        $provides = $entry->provides();
        if ($entry instanceof Abstraction) {
            $serviceProvider = $entry->getProviderClass();
            [$methodInstance, $classInstance, $methodParameters] = Factory::getMethodInstance('__invoke', $serviceProvider, $caller);
            $service = $methodInstance->invokeArgs(
                $classInstance,
                $methodParameters
            );
            if (!$service instanceof $provides) {
                throw new LogicException("Container expecting instance of $provides from service provider $serviceProvider");
            }
        } else {
            $service = Factory::getClassInstance($provides);
            if (!$service instanceof $provides) {
                throw new LogicException("Factory could not fabricate instance of $provides");
            }
        }

        return $service;
    }

    /**
     *
     * @return bool
     */
    public function hasService(string $nsClass): bool
    {
        return $this->services->has($nsClass);
    }

    /**
     *
     * @return bool
     */
    public function hasProviderFor(string $nsClass): bool
    {
        return $this->providers->has($nsClass);
    }

    /**
     *
     * Instantiate services that are not lazy in the service container
     *
     * @throws LogicException If trying to boot an already booted service container
     */
    public function boot(): void
    {
        if ($this->booted) {
            throw new LogicException('Service container already booted');
        }
        // Register itself to be available as a type hint in other services and service providers
        $this->register(
            new Concrete(
                __CLASS__,
                false,
                true
            )
        );
        $this->services->set($this, __CLASS__);
        // Boot services and service providers
        foreach ($this->providers->getAll() as $key => $entry) {
            if ($entry->getIsBooted()) {
                continue;
            }
            if (!$entry->isLazy()) {
                $this->get($entry->provides(), BootProcess::class);
            }
        }
        $this->booted = true;
    }

    /**
     *
     * Register services and service providers into container.
     * Allows setting of concrete service classes instances after container boot.
     *
     * @param Entry $entry
     * @param null|object $implementation Service instance
     * @throws LogicException When registering service ($implementation) instances before booting
     * @throws LogicException When $entry instance is not Concrete type and $implementation is not null
     */
    public function register(Entry $entry, ?object $implementation = null): self
    {
        if ($implementation) {
            $this->services->set($implementation, $entry->provides());
            $entry->setIsBooted();
        }

        $this->providers->set($entry, $entry->provides());
        return $this;
    }
}
