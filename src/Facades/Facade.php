<?php

declare(strict_types=1);

namespace Flows\Facades;

use Flows\Container\Container;

abstract class Facade
{
    protected static Container $container;
    private static bool $ready;

    /**
     *
     * Set the facade's container instance
     *
     * @param Container $container
     */
    public static function setContainer(Container $container): void
    {
        static::$container = $container;
        static::$ready = true;
    }

    /**
     *
     * Is the facade ready to be used?
     *
     * @return bool
     */
    public static function isReady(): bool
    {
        return isset(static::$ready);
    }

    /**
     * 
     * Get the service instance from the container. 
     * Must be defined in child class.
     * Acts as getting the service class from the container.
     */
    abstract public static function getServiceInstance(): object;
}
