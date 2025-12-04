<?php

declare(strict_types=1);

namespace Flows\Gates;

use Collectibles\Contracts\IO as IOContract;
use Flows\Contracts\Gates\Gate as GateContract;
use Flows\Contracts\Tasks\CleanUp as CleanUpContract;

abstract class Gate implements GateContract, CleanUpContract
{
    protected ?IOContract $io;

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

    /**
     * 
     * @return array<int, mixed>
     */
    public function __sleep(): array
    {
        return ['io'];
    }
}
