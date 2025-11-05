<?php

declare(strict_types=1);

namespace Flows\Gates;

abstract class XorGate extends Gate
{
    abstract public function __invoke(): string;
}
