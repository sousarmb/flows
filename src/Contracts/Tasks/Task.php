<?php

declare(strict_types=1);

namespace Flows\Contracts\Tasks;

use Collectibles\Contracts\IO;

interface Task extends CleanUp
{
    /**
     *
     * @param IO|null $io
     * @return IO|null
     */
    public function __invoke(?IO $io = null): ?IO;
}
