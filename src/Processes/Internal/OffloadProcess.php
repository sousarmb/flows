<?php

declare(strict_types=1);

namespace Flows\Processes\Internal;

use Collectibles\Collection;
use Collectibles\IO;
use Flows\ApplicationKernel;
use Flows\Contracts\Tasks\Task as TaskContract;
use Flows\Facades\Config;
use Flows\Facades\Events;
use Flows\Facades\Logger;
use Flows\Processes\Internal\Events\OffloadedProcessError;
use Flows\Processes\Internal\IO\OffloadedIO;
use Flows\Processes\Process;
use Flows\Reactor\Reactor;
use Flows\Traits\OffloadedProcess;
use RuntimeException;

class OffloadProcess extends Process
{
    public function __construct()
    {
        $this->tasks = [
            new class implements TaskContract {
                /**
                 * @param Collection|IO|null $io
                 * @return Collection|IO|null
                 */
                public function __invoke(Collection|IO|null $io = null): Collection|IO|null
                {
                    // change to OffloadedProcess.php script directory
                    if (!chdir(dirname(__FILE__, 3))) {
                        throw new RuntimeException('Could not change to OffloadedProcess.php script directory');
                    }
                    if (!is_readable('OffloadedProcess.php')) {
                        throw new RuntimeException('Could not find or read OffloadedProcess.php script');
                    }

                    return $io;
                }

                public function cleanUp(bool $forSerialization = false): void
                {
                    if (
                        !$forSerialization
                        && !chdir(STARTER_DIRECTORY)
                    ) {
                        throw new RuntimeException('Could not change directory to starter directory');
                    }
                }
            },
            new class implements TaskContract {
                /**
                 * @param Collection|IO|null $io
                 * @return Collection|IO|null
                 */
                public function __invoke(Collection|IO|null $io = null): Collection|IO|null
                {
                    $descriptorSpec = [
                        0 => ['pipe', 'r'], // STDIN (write to child process)
                        1 => ['pipe', 'w'], // STDOUT (read from child process)
                        2 => ['pipe', 'w'], // STDERR
                    ];
                    $processes = [];
                    foreach ($io->get('processes') as $processName) {
                        $process = proc_open(
                            ['php', 'OffloadedProcess.php'],
                            $descriptorSpec,
                            $pipes,
                            getcwd()
                        );
                        if (false === $process) {
                            throw new RuntimeException("Could not open process OffloadedProcess.php");
                        }

                        stream_set_blocking($pipes[0], false);
                        stream_set_blocking($pipes[1], false);
                        stream_set_blocking($pipes[2], false);
                        // store process data for further use
                        $processes[$processName] = [$process, $pipes[0], $pipes[1], $pipes[2]];
                    }
                    return new OffloadedIO($processes, $io->get('processIO'), $io->get('contentTerminator'));
                }

                public function cleanUp(bool $forSerialization = false): void {}
            },
            new class implements TaskContract {
                use OffloadedProcess;

                private bool $defaultStopOnOffloadError = true;
                /**
                 * @param Collection|IO|null $io
                 * @return Collection|IO|null
                 */
                public function __invoke(Collection|IO|null $io = null): Collection|IO|null
                {
                    $output = new Collection();
                    $reactor = new Reactor();
                    // Wait/read process output
                    foreach ($io->get('processes') as $processName => [$process, $stdin, $stdout, $stderr]) {
                        $reactor->onReadable($stderr, function ($stream, $reactor) use ($io, $stdout, $processName): void {
                            // Get process error data
                            $data = fgets($stream);
                            if ($data === $io->get('contentTerminator') . PHP_EOL) {
                                // Remove pipes to prevent infinite loop
                                $reactor->remove($stdout);
                                $reactor->remove($stream);
                                // Throw event (developer has to write a handler to it)
                                Events::handle(
                                    new OffloadedProcessError($processName, $io)
                                );
                                if (Config::getApplicationSettings()->get('stop.on_offload_error', $this->defaultStopOnOffloadError)) {
                                    // Stop reactor 
                                    // Other offloaded processes continue running for a while!
                                    $reactor->stopRun();
                                    // Stop the kernel from running other processes
                                    ApplicationKernel::fullStop();
                                }
                            } elseif (!empty($data)) {
                                Logger::alert('Unexpected output from offloaded process', [$data]);
                            }
                            if (feof($stream)) {
                                $reactor->remove($stream);
                            }
                        });
                        $reactor->onWritable($stdin, function ($stream, $reactor) use ($processName, $io): void {
                            // Send work to command
                            $message = sprintf(
                                '%s|%s|%s|%s' . PHP_EOL,
                                $processName,
                                $io->get('contentTerminator'),
                                Config::getRootDirectory(),
                                base64_encode(serialize($io->get('processIO')))
                            );
                            if (!fwrite($stream, $message)) {
                                throw new RuntimeException("Could not pipe process setup to child process $processName");
                            }
                            // Setup piped, one less stream to write to
                            $reactor->remove($stream);
                        });
                        $reactor->onReadable($stdout, function ($stream, $reactor) use ($processName, $io, $output): void {
                            // Get process data
                            $data = fgets($stream);
                            if ($data === $io->get('contentTerminator') . PHP_EOL) {
                                // Return stored, one less stream to listen to
                                $reactor->remove($stream);
                            } elseif (!empty($data)) {
                                /* Store process output in the "processIO" collection. The main process will be 
                                 * listening to it and will know which process it belongs to by the key.
                                 * Using unserialize() because child process output is an instance of Collection 
                                 * or IO. This way we get the class back just as it was returned by the child 
                                 * process. base64_encode() is used to prevent any issues with special characters 
                                 * in the output. */
                                $return = unserialize(
                                    base64_decode($data),
                                    ['allowed_classes' => [Collection::class, IO::class]]
                                );
                                if (!($return instanceof Collection
                                    || $return instanceof IO)) {
                                    throw new RuntimeException('Unexpected object type: ' . get_class($return));
                                }

                                $output->set($return, $processName);
                            }
                            if (feof($stream)) {
                                $reactor->remove($stream);
                            }
                        });
                    }
                    $frequency = Config::getApplicationSettings()->get('offloaded_process_status_check_frequency', 0.01  /* 10ms */);
                    /* Check if process(es) is still running, if not, remove all pipes to prevent infinite 
                     * loop. This is a safety net in case something unexpected happens and we miss the 
                     * content terminator, or it never comes for some reason. */
                    $reactor->addTimer($frequency, function ($reactor) use ($io): void {
                        foreach ($io->get('processes') as [$process, $stdin, $stdout, $stderr]) {
                            if (!proc_get_status($process)['running']) {
                                if (
                                    $reactor->hasHandler($stderr)
                                    && feof($stderr)
                                ) {
                                    // Offloaded process over, all data read
                                    $reactor->remove($stderr);
                                }
                                if (
                                    $reactor->hasHandler($stdout)
                                    && feof($stdout)
                                ) {
                                    // Offloaded process over, all data read
                                    $reactor->remove($stdout);
                                }
                                // $stdin was removed already
                            }
                        }
                        if (!$reactor->hasHandlers()) {
                            $reactor->stopRun();
                        }
                    }, true);
                    $reactor->run();
                    return $output;
                }

                public function cleanUp(bool $forSerialization = false): void {}
            },
        ];

        parent::__construct();
    }
}
