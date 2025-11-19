<?php

declare(strict_types=1);

namespace Flows\Processes\Internal\Events;

use Collectibles\Contracts\IO;
use Flows\Event\Event;

readonly class OffloadedProcessError extends Event
{
    public function __construct(
        private string $nsProcess,
        private ?IO $io = null
    ) {}
}
