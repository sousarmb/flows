<?php

declare(strict_types=1);

namespace Flows\Container;

class Caller
{
    public function __construct(
        private ?string $caller = null
    ) {
    }

    public function get(): string
    {
        return $this->caller;
    }
}
