<?php

declare(strict_types=1);

namespace Flows\Contracts\Gates;

interface PipeListener
{
    /**
     * Get pipe to listen
     */
    public function getPipe(): mixed;
}
