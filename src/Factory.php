<?php

declare(strict_types=1);

namespace Flows;

use Closure;
use Flows\Container\Caller;
use Flows\Container\Container;
use Flows\Contracts\Factory as FactoryContract;
use ReflectionClass;
use ReflectionException;
use ReflectionFunction;
use ReflectionMethod;

abstract class Factory implements FactoryContract
{
    private static Container $container;
    private static array $scalarOrNot = [
        'array',
        'bool',
        'float',
        'int',
        'null',
        'string'
    ];

    /**
     *
     * @param Closure|string $needed The class or closure that needs building
     * @param null|string $caller The caller class or method
     * @return mixed The required class instance or the result of the closure execution
     * @throws ReflectionException
     */
    public static function getClassInstance(
        Closure|string $needed,
        ?string $caller = null
    ): mixed {
        $parameters = [];
        $isClosure = $needed instanceof Closure;
        $reflection = $isClosure ? new ReflectionFunction($needed) : new ReflectionClass($needed);
        if ($isClosure) {
            $parameters = $reflection->getParameters();
            if ($parameters) {
                $parameters = static::getParametersValue($parameters, $caller);
            }

            return $reflection->invokeArgs($parameters);
        }
        if (!$reflection->isInstantiable()) {
            throw new ReflectionException("$needed is not instantiable");
        }

        $classConstructor = $reflection->getConstructor();
        if ($classConstructor) {
            $parameters = static::getParametersValue(
                $classConstructor->getParameters(),
                $caller
            );
        }

        return $reflection->newInstanceArgs($parameters);
    }

    /**
     *
     * @param string $methodName
     * @param string $inClass
     * @param null|string $caller The caller class or method
     * @return array<int, mixed>
     * @throws ReflectionException
     */
    public static function getMethodInstance(
        string $methodName,
        string $inClass,
        ?string $caller = null
    ): array {
        $classInstance = static::getClassInstance($inClass, $caller);
        $methodInstance = new ReflectionMethod(
            $classInstance,
            $methodName
        );
        if (!$methodInstance->isPublic()) {
            throw new ReflectionException("$inClass::$methodName() must be public");
        }

        return [
            $methodInstance,
            $classInstance,
            static::getParametersValue(
                $methodInstance->getParameters(),
                $caller
            )
        ];
    }

    /**
     *
     * @param array $parameters
     * @return array
     */
    private static function getParametersValue(
        array $parameters,
        ?string $caller = null
    ): array {
        $values = [];
        if (!$parameters) {
            return $values;
        }

        foreach ($parameters as $parameter) {
            $type = $parameter->getType();
            if (null === $type
                || in_array($type->getName(), self::$scalarOrNot, true)
            ) {
                $values[] = $parameter->isDefaultValueAvailable() === true ? $parameter->getDefaultValue() : null;
            } elseif ($type->getName() === Caller::class) {
                $values[] = new Caller($caller);
            } else {
                static::getDependencyForInjection($type->getName(), $values, $caller);
            }
        }
        return $values;
    }

    /**
     *
     * @param string $nsAbstractionOrConcrete
     * @param array<int, mixed> $values
     * @param null|string $caller The caller class or method
     * @return void
     */
    private static function getDependencyForInjection(
        string $nsAbstractionOrConcrete,
        array &$values,
        ?string $caller = null
    ): void {
        $values[] = static::$container->hasProviderFor($nsAbstractionOrConcrete)
            ? static::$container->get($nsAbstractionOrConcrete, $caller)
            : self::getClassInstance($nsAbstractionOrConcrete, $caller);
    }

    /**
     *
     * Inject container for factory's use when fabricating class instances
     */
    public static function setContainer(Container $container): void
    {
        static::$container = $container;
    }
}
