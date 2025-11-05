<?php

declare(strict_types=1);

namespace Flows\Facades;

class Container extends Facade
{
    public static function __callStatic($name, $arguments): mixed
    {
        return self::$container->$name(...$arguments);
    }
}
