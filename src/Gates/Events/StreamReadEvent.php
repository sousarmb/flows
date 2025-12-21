<?php

declare(strict_types=1);

namespace Flows\Gates\Events;

use Flows\Contracts\Gates\GateEvent as GateEventContract;
use Flows\Contracts\Gates\Stream as StreamContract;

/**
 * 
 * Event gate event, wait till a something is written on a given stream
 */
abstract readonly class StreamReadEvent implements GateEventContract, StreamContract
{
    /**
     * @var resource stream
     */
    protected mixed $stream;

    public function getStream(): mixed
    {
        return $this->stream;
    }
}
