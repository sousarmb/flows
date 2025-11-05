<?php

declare(strict_types=1);

namespace Flows\Contracts;

use Flows\Event\Event;

interface EventHandler
{
    public function handle(Event $event): void;
}
