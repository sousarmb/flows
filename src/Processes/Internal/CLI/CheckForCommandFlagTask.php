<?php

namespace Flows\Processes\Internal\CLI;

use Collectibles\Contracts\IO as IOContract;
use Flows\Contracts\Tasks\Task as TaskContract;
use Flows\Processes\Internal\IO\CLICollection;
use LogicException;

class CheckForCommandFlagTask implements TaskContract
{
    /**
     * @param IOContract|CLICollection|null $io
     * @return IOContract|null
     */
    public function __invoke(?IOContract $io = null): ?IOContract
    {
        if (!defined('FLOWS_COMMAND_CONTEXT')) {
            throw new LogicException('Available through flows binary only');
        }

        return $io;
    }

    public function cleanUp(bool $forSerialization = false): void {}
}
