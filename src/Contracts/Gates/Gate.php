<?php

declare(strict_types=1);

namespace Flows\Contracts\Gates;

use Collectibles\Collection;
use Collectibles\IO;
use Flows\Contracts\Tasks\CleanUp as CleanUpContract;

interface Gate extends CleanUpContract
{
    /**
     * @param Collection|IO|null $io
     * @return self
     */
    public function setIO(Collection|IO|null $io = null): self;

    /**
     * @return Collection|IO|null
     */
    public function getIO(): Collection|IO|null;
}
