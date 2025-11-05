<?php

declare(strict_types=1);

namespace Flows\Contracts\Container;

use Flows\Container\Caller;

interface ServiceProvider
{
    public function __invoke(?Caller $caller = null): object;
}
