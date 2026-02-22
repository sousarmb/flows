<?php

declare(strict_types=1);

namespace Flows\Gates;

use Collectibles\Collection;
use Collectibles\IO;
use Flows\Contracts\Gates\Gate as GateContract;
use Flows\Contracts\Tasks\CleanUp as CleanUpContract;

abstract class Gate implements GateContract, CleanUpContract
{
    protected Collection|IO|null $io;

    /**
     * @param Collection|IO|null $io
     * @return self
     */
    public function setIO(Collection|IO|null $io = null): self
    {
        $this->io = $io;
        return $this;
    }

    /**
     * @return Collection|IO|null
     */
    public function getIO(): Collection|IO|null
    {
        return $this->io;
    }

    /**
     * @return array<int, mixed>
     */
    public function __sleep(): array
    {
        return ['io'];
    }

    abstract public function cleanUp(bool $forSerialization = false): void;
}
