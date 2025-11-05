<?php

declare(strict_types=1);

namespace Flows\Facades;

use Flows\Observer\Kernel as ObserverKernel;

class Observers extends Facade
{
    public static function __callStatic($name, $arguments): mixed
    {
        return self::$container->get(ObserverKernel::class)->$name(...$arguments);
    }
}
