<?php

declare(strict_types=1);

namespace Flows\Processes\Internal\IO;

use Collectibles\Contracts\IO as IOContract;

readonly class OffloadedIO extends ContentTerminatorIO
{
    public function __construct(
        protected array $processes,
        protected ?IOContract $processIO = null,
        ?string $contentTerminator = null
    ) {
        parent::__construct($contentTerminator);
    }
}
