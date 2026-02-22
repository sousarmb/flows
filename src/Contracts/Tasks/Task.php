<?php

declare(strict_types=1);

namespace Flows\Contracts\Tasks;

use Collectibles\Collection;
use Collectibles\IO;

interface Task extends CleanUp
{
    /**
     *
     * @param Collection|IO|null $io 
     * @return Collection|IO|null
     */
    public function __invoke(Collection|IO|null $io = null): Collection|IO|null;
}
