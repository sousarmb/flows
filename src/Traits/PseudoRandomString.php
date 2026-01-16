<?php

declare(strict_types=1);

namespace Flows\Traits;

trait PseudoRandomString
{
    public function getAlphaNumeric(
        ?string $prefix = null,
        ?string $suffix = null
    ): string {
        $string = str_shuffle('0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ');
        if ($prefix) {
            $string = "{$prefix}{$string}";
        }
        if ($suffix) {
            $string .= $suffix;
        }

        return $string;
    }
}
