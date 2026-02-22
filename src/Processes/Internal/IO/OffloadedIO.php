<?php

declare(strict_types=1);

namespace Flows\Processes\Internal\IO;

use Collectibles\Collection;
use Collectibles\IO;

readonly class OffloadedIO extends ContentTerminatorIO
{
    public function __construct(
        protected array $processes,
        protected Collection|IO|null $processIO = null,
        ?string $contentTerminator = null
    ) {
        parent::__construct($contentTerminator);
    }
}
