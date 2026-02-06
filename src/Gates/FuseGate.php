<?php

declare(strict_types=1);

namespace Flows\Gates;

use Flows\Contracts\NoYesReturn as NoYesReturnContract;

/**
 * 
 * Define if process should continue or exit
 */
abstract class FuseGate extends Gate implements NoYesReturnContract
{
    protected bool $noYesReturn = true;

    /**
     * Exit process
     * 
     * @return bool TRUE => continue, FALSE => exit (blown fuse)
     */
    abstract public function __invoke(): bool;

    /**
     * Return on process exit
     * 
     * @return bool TRUE => return current IO, FALSE => return null
     */
    public function noYesReturn(): bool
    {
        return $this->noYesReturn;
    }
}
