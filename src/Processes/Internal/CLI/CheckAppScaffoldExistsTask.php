<?php

namespace Flows\Processes\Internal\CLI;

use Collectibles\Collection;
use Collectibles\IO;
use Flows\Contracts\Tasks\Task as TaskContract;
use Flows\Processes\Internal\IO\CLICollection;
use LogicException;

class CheckAppScaffoldExistsTask implements TaskContract
{
    /**
     * @param CLICollection|Collection|IO|null $io
     * @throws LogicException If the scaffold destination directory does not exist
     * @return Collection|IO|null
     */
    public function __invoke(CLICollection|Collection|IO|null $io = null): Collection|IO|null
    {
        if (!is_dir($io->getScaffoldDestinationDirectory())) {
            throw new LogicException('Application scaffold does not exist. Run - vendor/bin/flows create:scaffold - to create it first');
        }

        return $io;
    }

    public function cleanUp(bool $forSerialization = false): void {}
}
