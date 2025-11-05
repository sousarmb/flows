<?php

declare(strict_types=1);

namespace Flows\Contracts;

interface Factory
{
    /**
     *
     * @param string $implementation
     * @return mixed
     */
    public static function getClassInstance(string $implementation): mixed;

    /**
     *
     * @param string $methodName
     * @param string $implementation
     * @return array
     */
    public static function getMethodInstance(
        string $methodName,
        string $implementation
    ): array;
}
