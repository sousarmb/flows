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
     * Set the façade's container instance
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
     * Is the façade ready to be used?
     *
     * @return bool
     */
    public static function isReady(): bool
    {
        return isset(static::$ready);
    }
}
