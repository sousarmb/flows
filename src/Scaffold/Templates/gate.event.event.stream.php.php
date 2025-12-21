<?php

declare(strict_types=1);

namespace App\Events;

use Flows\Gates\Events\StreamReadEvent;

readonly class <!--name-->GateEvent extends StreamReadEvent
{
    /**
     * @param string $streamFile
     */
    public function __construct(string $streamFile)
    {
        $this->stream = fopen($streamFile, 'rn');
    }

    /**
     * @param mixed $data 
     * @return bool TRUE => event wins gate race condition; FALSE => try again in $frequency time
     */
    public function resolve($data = null): bool {}
}
