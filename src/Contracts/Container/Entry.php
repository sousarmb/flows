<?php

declare(strict_types=1);

namespace Flows\Contracts\Container;

interface Entry
{
    public function provides(): string;
    public function getIsBooted(): bool;
    public function setIsBooted(): void;
}
