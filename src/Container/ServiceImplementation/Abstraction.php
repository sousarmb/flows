<?php

declare(strict_types=1);

namespace Flows\Container\ServiceImplementation;

class Abstraction extends Concrete
{
    /**
     *
     * @param string $nsProvides  The name of the interface the returned class must implement
     * @param string $nsProviderClass   The provider class
     * @param bool $is_lazy Should the container run the provider on boot (or not)
     * @param bool $is_singleton    Should the container handle the provided service as a singleton
     */
    public function __construct(
        string $nsProvides,
        private string $nsProviderClass,
        bool $is_lazy = true,
        bool $is_singleton = false
    ) {
        parent::__construct($nsProvides, $is_lazy, $is_singleton);
    }

    /**
     *
     * @return string The name of the service provider class
     */
    public function getProviderClass(): string
    {
        return $this->nsProviderClass;
    }
}
