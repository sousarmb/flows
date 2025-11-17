<?php

declare(strict_types=1);

namespace Flows\Traits;

trait PseudoRandomString
{
    public function getAlphaNumeric(): string
    {
        return str_shuffle('0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ');
    }
}
