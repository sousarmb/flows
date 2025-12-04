<?php

declare(strict_types=1);

namespace Flows\Contracts\Gates;

interface GateEvent
{
    /**
     * Use logic to decide if this gate event wins race condition
     */
    public function resolve($data = null): bool;
}
