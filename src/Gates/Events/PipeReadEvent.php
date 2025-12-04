<?php

declare(strict_types=1);

namespace Flows\Gates\Internal\Events;

use Flows\Contracts\Gates\GateEvent as GateEventContract;
use Flows\Contracts\Gates\PipeListener as PipeListenerContract;
use LogicException;
use RuntimeException;

/**
 * 
 * Event gate, wait till a something is written on a given pipe file
 */
abstract readonly class PipeReadEvent implements GateEventContract, PipeListenerContract
{
    /**
     * @var resource pipe (file handle)
     */
    protected mixed $fHandle;

    public function __construct(
        /**
         * @var string file to check for modifications (pipe)
         */
        private string $filePath,
    ) {
        if (!is_readable($filePath)) {
            throw new LogicException('Pipe file must exist and be readable to gate event');
        }

        $fHandle = fopen($this->filePath, 'r');
        if (false === $fHandle) {
            throw new RuntimeException('Could not open pipe file fo reading');
        }

        $this->fHandle = $fHandle;
    }

    public function getPipe(): mixed
    {
        return $this->fHandle;
    }
}
