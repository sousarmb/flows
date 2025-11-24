<?php

declare(strict_types=1);

namespace Flows\Contracts\Tasks;

interface CleanUp
{
    /**
     * Runs when process/task is completed
     * 
     * @param bool $forSerialization TRUE => prepare for serialization, FALSE => normal termination
     */
    public function cleanUp(bool $forSerialization = false): void;
}
