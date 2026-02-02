<?php

declare(strict_types=1);

namespace Flows\Contracts\Gates;

interface GateEvent
{
    /**
     * Use logic to decide if this gate event wins race condition
     * 
     * @param string|resource
     * @return bool Signal the event gate to keep the reactor running or stop it
     */
    public function resolve(mixed $mixed = null): bool;
}
