<?php

declare(strict_types=1);

namespace Flows\Gates;

/**
 * 
 * Parallel gate, all outgoing paths are taken simultaneously but in same process (#PID), join and resume main process.
 */
abstract class AndGate extends Gate
{
    abstract public function __invoke(): array;
}
