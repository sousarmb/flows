<?php

declare(strict_types=1);

namespace Flows\Facades;

use Flows\Event\Kernel as EventKernel;

class Events extends Facade
{
    public static function __callStatic($name, $arguments): mixed
    {
        return self::$container->get(EventKernel::class)->$name(...$arguments);
    }

    public static function getServiceInstance(): EventKernel
    {
        return self::$container->get(EventKernel::class);
    }
}
