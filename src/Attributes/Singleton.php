<?php

declare(strict_types=1);

namespace Flows\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class Singleton
{
    public function __construct(
        private bool $isSingleton = true
    ) {
    }

    public function getIsSingleton(): bool
    {
        return $this->isSingleton;
    }
}
