<?php

declare(strict_types=1);

namespace Flows\Processes\Internal\Events;

use Collectibles\Collection;
use Collectibles\IO;
use Flows\Event\Event;

readonly class OffloadedProcessError extends Event
{
    public function __construct(
        private string $nsProcess,
        private Collection|IO|null $io = null
    ) {}
}
