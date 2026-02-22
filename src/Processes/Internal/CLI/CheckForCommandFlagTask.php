<?php

namespace Flows\Processes\Internal\CLI;

use Collectibles\Collection;
use Collectibles\IO;
use Flows\Contracts\Tasks\Task as TaskContract;
use Flows\Processes\Internal\IO\CLICollection;
use LogicException;

class CheckForCommandFlagTask implements TaskContract
{
    /**
     * @param Collection|IO|null $io
     * @throws LogicException If the command context constant is not defined
     * @return Collection|IO|null
     */
    public function __invoke(CLICollection|Collection|IO|null $io = null): CLICollection|Collection|IO|null
    {
        if (!defined('FLOWS_COMMAND_CONTEXT')) {
            throw new LogicException('Available through flows binary only');
        }

        return $io;
    }

    public function cleanUp(bool $forSerialization = false): void {}
}
