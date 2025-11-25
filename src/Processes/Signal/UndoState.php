<?php

declare(strict_types=1);

namespace Flows\Processes\Signal;

use Collectibles\Contracts\IO as IOContract;
use Flows\Contracts\Tasks\CleanUp as CleanUpContract;

abstract class UndoState implements CleanUpContract
{
    protected ?IOContract $io;

    abstract public function __invoke(): int;

    /**
     *
     * @param IOContract|null $io
     * @return self
     */
    public function setIO(?IOContract $io = null): self
    {
        $this->io = $io;
        return $this;
    }

    /**
     *
     * @return IO|null
     */
    public function getIO(): ?IOContract
    {
        return $this->io;
    }
}
