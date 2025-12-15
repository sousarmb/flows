<?php

namespace Flows\Processes\Internal\CLI;

use Collectibles\Contracts\IO as IOContract;
use Flows\Contracts\Tasks\Task as TaskContract;
use Flows\Processes\Internal\IO\CLICollection;
use LogicException;

class CheckAppScaffoldExists implements TaskContract
{
    /**
     * @param IOContract|CLICollection|null $io
     * @return IOContract|null
     */
    public function __invoke(?IOContract $io = null): ?IOContract
    {
        if (!is_dir($io->getScaffoldDestinationDirectory())) {
            throw new LogicException('Application scaffold does not exist. Run - vendor/bin/flows create:scaffold - to create it first');
        }

        return $io;
    }

    public function cleanUp(bool $forSerialization = false): void {}
}
