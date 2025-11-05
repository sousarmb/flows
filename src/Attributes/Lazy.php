<?php

declare(strict_types=1);

namespace Flows\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class Lazy
{
    public function __construct(
        private bool $isLazy = true
    ) {
    }

    public function getIsLazy(): bool
    {
        return $this->isLazy;
    }
}
