<?php

declare(strict_types=1);

namespace Flows\Contracts\Gates;

interface Stream
{
    /**
     * Get resource to read/write
     * 
     * @return resource
     */
    public function getResource(): mixed;

    /**
     * Close used resource(s)
     */
    public function closeResource(): void;
}
