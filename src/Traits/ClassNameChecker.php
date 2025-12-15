<?php

declare(strict_types=1);

namespace Flows\Traits;

trait ClassNameChecker
{
    const VALID_CLASS_REGEX = "/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*$/";

    /**
     * Check if string can be used as a class name
     * 
     * @param string $name
     * @return bool TRUE => valid, FALSE => not valid
     */
    public function isValid(string $name): bool
    {
        return 1 === preg_match(self::VALID_CLASS_REGEX, $name);
    }
}
