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

    /**
     * @return array<int, mixed> Names of members to be serialized
     */
    public function __sleep(): array;

    /**
     * Do everything needed to restore object to ready state
     */
    public function __wakeup();
}
