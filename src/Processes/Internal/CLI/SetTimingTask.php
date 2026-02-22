<?php

namespace Flows\Processes\Internal\CLI;

use Collectibles\Collection;
use Collectibles\IO;
use Flows\Contracts\Tasks\Task as TaskContract;
use Flows\Processes\Internal\IO\CLICollection;
use InvalidArgumentException;

class SetTimingTask implements TaskContract
{
    /**
     * @param CLICollection|Collection|IO|null $io
     * @throws InvalidArgumentException If timing is invalid
     * @return Collection|IO|null
     */
    public function __invoke(CLICollection|Collection|IO|null $io = null): Collection|IO|null
    {
        if ($timing = $io->get('argv.timing')) {
            if (!in_array($timing, ['realtime', 'defer_process', 'defer_flow'])) {
                throw new InvalidArgumentException('Timing is: realtime|defer_process|defer_flow');
            }

            return $io;
        }
        // Query the user
        $tryAgain = true;
        do {
            $timing = readline('Is realtime|defer_process|defer_flow? ');
            if (!in_array($timing, ['realtime', 'defer_process', 'defer_flow'])) {
                echo PHP_EOL . 'Invalid timing' . PHP_EOL;
            } else {
                $io->add($timing, 'argv.timing');
                $tryAgain = false;
            }
        } while ($tryAgain);
        return $io;
    }

    public function cleanUp(bool $forSerialization = false): void {}
}
