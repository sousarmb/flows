<?php

declare(strict_types=1);

namespace Flows\Contracts;

use Collectibles\Contracts\IO as IOContract;
use Flows\Contracts\Tasks\CleanUp;

interface Gate extends CleanUp
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
