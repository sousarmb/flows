<?php

declare(strict_types=1);

namespace Flows\Processes\Internal\IO;

use Collectibles\IO;
use Ramsey\Uuid\Uuid;

readonly class ContentTerminatorIO extends IO
{
    protected string $contentTerminator;

    public function __construct(?string $contentTerminator = null)
    {
        $this->contentTerminator = $contentTerminator ?? '---' . Uuid::uuid4()->toString() . '---';
    }
}
