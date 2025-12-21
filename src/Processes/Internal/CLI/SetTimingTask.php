<?php

namespace Flows\Processes\Internal\CLI;

use Collectibles\Contracts\IO as IOContract;
use Flows\Contracts\Tasks\Task as TaskContract;
use Flows\Processes\Internal\IO\CLICollection;
use InvalidArgumentException;

class SetTimingTask implements TaskContract
{
    /**
     * @param IOContract|CLICollection|null $io
     * @return IOContract|null
     */
    public function __invoke(?IOContract $io = null): ?IOContract
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
