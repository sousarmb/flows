<?php

declare(strict_types=1);

namespace Flows\Gates;

abstract class OrGate extends Gate
{
    abstract public function __invoke(): array;
}
