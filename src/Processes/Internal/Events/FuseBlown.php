<?php

declare(strict_types=1);

namespace Flows\Processes\Internal\Events;

use Flows\Event\Event;

readonly class FuseBlown extends Event
{
    public function __construct(
        private string $nsProcess,
        private ?int $taskKey = null
    ) {}
}
