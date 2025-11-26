<?php

declare(strict_types=1);

namespace Flows\Gates;

/**
 * 
 * Exclusive gate, only one outgoing path is chosen (based on conditions or data)
 */
abstract class XorGate extends Gate
{
    abstract public function __invoke(): string;
}
