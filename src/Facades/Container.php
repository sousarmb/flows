<?php

declare(strict_types=1);

namespace Flows\Facades;

use Flows\Container\Container as ContainerService;

class Container extends Facade
{
    public static function __callStatic($name, $arguments): mixed
    {
        return self::$container->$name(...$arguments);
    }

    public static function getServiceInstance(): ContainerService
    {
        return self::$container;
    }
}
