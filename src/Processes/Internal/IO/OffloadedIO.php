<?php

declare(strict_types=1);

namespace Flows\Processes\Internal\IO;

use Collectibles\Contracts\IO;

readonly class OffloadedIO extends ContentTerminatorIO
{
    public function __construct(
        protected array $processes,
        protected ?IO $processIO = null,
        ?string $contentTerminator = null
    ) {
        parent::__construct($contentTerminator);
    }
}
