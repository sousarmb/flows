<?php

declare(strict_types=1);

namespace Flows\Gates;

use Collectibles\Contracts\IO;
use Flows\Contracts\Gate as GateContract;

abstract class Gate implements GateContract
{
    protected ?IO $io;

    /**
     *
     * @param IO|null $io
     * @return self
     */
    public function setIO(?IO $io = null): self
    {
        $this->io = $io;
        return $this;
    }

    /**
     *
     * @return IO|null
     */
    public function getIO(): ?IO
    {
        return $this->io;
    }

    /**
     *
     * @return void
     */
    public function cleanUp(): void {}

    /**
     * 
     * @return array<int, mixed>
     */
    public function __sleep(): array
    {
        return ['io'];
    }
}
