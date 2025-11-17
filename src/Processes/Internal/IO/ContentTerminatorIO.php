<?php

declare(strict_types=1);

namespace Flows\Processes\Internal\IO;

use Collectibles\IO;
use Flows\Traits\PseudoRandomString;

readonly class ContentTerminatorIO extends IO
{
    use PseudoRandomString;

    protected string $contentTerminator;

    public function __construct(?string $contentTerminator = null)
    {
        $this->contentTerminator = $contentTerminator ?? '---' . $this->getAlphaNumeric() . '---';
    }
}
