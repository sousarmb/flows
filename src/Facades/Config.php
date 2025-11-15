<?php

declare(strict_types=1);

namespace Flows\Facades;

use Collectibles\Collection;
use Flows\Config as ConfigService;

class Config extends Facade
{
    public static function __callStatic($name, $arguments): mixed
    {
        return self::$container->get(ConfigService::class)->$name(...$arguments);
    }

    public static function getRootDirectory(): string
    {
        return self::$container->get(ConfigService::class)->get('root.directory');
    }

    public static function getApplicationDirectory(): string
    {
        return self::$container->get(ConfigService::class)->get('app.directory');
    }

    public static function getConfigDirectory(): string
    {
        return self::$container->get(ConfigService::class)->get('app.config.directory');
    }

    public static function getLogDirectory(): string
    {
        return self::$container->get(ConfigService::class)->get('app.log.directory');
    }

    public static function getConfigFiles(): array
    {
        return self::$container->get(ConfigService::class)->get('app.config.files');
    }

    public static function getApplicationSettings(): Collection
    {
        return self::$container->get(ConfigService::class)->get('app.config.app');
    }

    public static function getServiceInstance(): ConfigService
    {
        return self::$container->get(ConfigService::class);
    }
}
