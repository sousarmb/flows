<?php

declare(strict_types=1);

namespace Flows\Contracts\Tasks;

use Collectibles\Contracts\IO as IOContract;

interface Task extends CleanUp
{
    /**
     *
     * @param IOContract|null $io
     * @return IOContract|null
     */
    public function __invoke(?IOContract $io = null): ?IOContract;
}
