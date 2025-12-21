<?php

declare(strict_types=1);

namespace Flows\Contracts\Gates;

interface Stream
{
    /**
     * Get stream to listen
     */
    public function getStream(): mixed;
}
