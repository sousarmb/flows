<?php

declare(strict_types=1);

namespace Flows\Traits;

trait RandomString
{
    public function getHexadecimal(
        int $length = 8,
        ?string $prefix = null,
        ?string $suffix = null
    ): string {
        $string = bin2hex(random_bytes($length));
        if ($prefix) {
            $string = "{$prefix}{$suffix}";
        }
        if ($suffix) {
            $string .= $suffix;
        }

        return $string;
    }
}
