<?php

declare(strict_types=1);

namespace Flows\Contracts;

use Collectibles\Contracts\IO;
use Flows\Contracts\Tasks\CleanUp;

interface Gate extends CleanUp
{
    /**
     *
     * @param IO|null $io
     * @return self
     */
    public function setIO(?IO $io = null): self;

    /**
     *
     * @return IO|null
     */
    public function getIO(): ?IO;
}
