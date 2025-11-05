<?php

declare(strict_types=1);

namespace Flows\Contracts;

interface Observer
{
    public function observe(object $subject): void;
}
