<?php

declare(strict_types=1);

namespace Flows\Contracts\Gates;

use Collectibles\Contracts\IO as IOContract;
use Flows\Contracts\Tasks\CleanUp as CleanUpContract;

interface Gate extends CleanUpContract
{
    /**
     *
     * @param IOContract|null $io
     * @return self
     */
    public function setIO(?IOContract $io = null): self;

    /**
     *
     * @return IO|null
     */
    public function getIO(): ?IOContract ;
}
