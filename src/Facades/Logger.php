<?php

declare(strict_types=1);

namespace Flows\Facades;

use Monolog\Logger as MonologLogger;

/**
 * 
 * @method static debug(string $message, ?array $extra)  
 * @method static info(string $message, ?array $extra)  
 * @method static notice(string $message, ?array $extra)  
 * @method static warning(string $message, ?array $extra)  
 * @method static error(string $message, ?array $extra)  
 * @method static critical(string $message, ?array $extra)  
 * @method static alert(string $message, ?array $extra)  
 * @method static emergency(string $message, ?array $extra)  
 */
class Logger extends Facade
{
    public static function __callStatic($name, $arguments): mixed
    {
        return self::$container->get(Logger::class)->$name(...$arguments);
    }

    public static function getServiceInstance(): MonologLogger
    {
        return self::$container->get(MonologLogger::class);
    }
}
