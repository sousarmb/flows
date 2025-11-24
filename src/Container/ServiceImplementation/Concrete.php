<?php

declare(strict_types=1);

namespace Flows\Container\ServiceImplementation;

use Flows\Contracts\Container\Entry as EntryContract;

class Concrete implements EntryContract
{
    public function __construct(
        protected string $nsClass,
        protected bool $is_lazy = true,
        protected bool $is_singleton = false,
        protected bool $booted = false
    ) {
    }

    /**
     *
     * @return bool If the service container is to handle this has a lazy loaded class
     */
    public function isLazy(): bool
    {
        return $this->is_lazy;
    }

    public function isSingleton(): bool
    {
        return $this->is_singleton;
    }

    /**
     *
     * @return string The concrete implementation class name or the name of the interface the returned class must implement
     */
    public function provides(): string
    {
        return $this->nsClass;
    }

    public function getIsBooted(): bool
    {
        return $this->booted;
    }

    public function setIsBooted(): void
    {
        $this->booted = true;
    }
}
