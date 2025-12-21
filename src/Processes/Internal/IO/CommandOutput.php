<?php

declare(strict_types=1);

namespace Flows\Processes\Internal\IO;

use Collectibles\IO;

readonly class CommandOutput extends IO
{
    public function __construct(
        protected string $message,
        protected bool $success
    ) {}
}
