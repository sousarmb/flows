<?php

declare(strict_types=1);

namespace Flows\Processes\Internal;

use Collectibles\Collection;
use Collectibles\Contracts\IO;
use Flows\ApplicationKernel;
use Flows\Contracts\Tasks\Task;
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
            new class implements Task {
                public function __invoke(?IO $io = null): ?IO
                {
                    // change to OffloadedProcess.php script directory
                    if (!chdir(__DIR__ . '/../..')) {
                        throw new RuntimeException('Could not change to OffloadedProcess.php script directory');
                    }
                    if (!is_readable('OffloadedProcess.php')) {
                        throw new RuntimeException('Could not find or read OffloadedProcess.php script');
                    }

                    return $io;
                }

                public function cleanUp(): void {}
            },
            new class implements Task {
                public function __invoke(?IO $io = null): ?IO
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
                        stream_set_blocking($pipes[0], false);
                        stream_set_blocking($pipes[1], false);
                        stream_set_blocking($pipes[2], false);
                        // store process data for further use
                        $processes[$processName] = [$process, $pipes[0], $pipes[1], $pipes[2]];
                    }
                    return new OffloadedIO($processes, $io->get('processIO'), $io->get('contentTerminator'));
                }

                public function cleanUp(): void {}
            },
            new class implements Task {
                use OffloadedProcess;

                private array $processes;
                private bool $default_stop_on_offload_error = true;
                public function __invoke(?IO $io = null): ?IO
                {
                    $this->processes = $io->get('processes');
                    $output = new Collection();
                    $reactor = new Reactor();
                    // Wait/read process output
                    foreach ($io->get('processes') as $processName => [$process, $stdin, $stdout, $stderr]) {
                        $reactor->onReadable($stderr, function ($stream, $reactor) use ($io, $stdout, $processName) {
                            // Get process error data
                            $data = fgets($stream);
                            if ($data === $io->get('contentTerminator') . PHP_EOL) {
                                // Remove pipes to prevent infinite loop
                                $reactor->remove($stdout);
                                $reactor->remove($stream);
                                // Throw event, developer has to write a handler to it
                                Events::handle(
                                    new OffloadedProcessError($processName, $io)
                                );
                                if (Config::getApplicationSettings()->get('stop.on_offload_error', $this->default_stop_on_offload_error)) {
                                    // Stop reactor 
                                    // Other offloaded processes continue running for a while!
                                    $reactor->stopRun();
                                    // Stop the kernel from running other processes
                                    ApplicationKernel::fullStop();
                                }
                            } elseif ($data) {
                                Logger::alert('Unexpected output from offloaded process', [$data]);
                            } elseif (!$data) {
                                $reactor->remove($stream);
                            }
                        });
                        $reactor->onWritable($stdin, function ($stream, $reactor) use ($processName, $io) {
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
                        $reactor->onReadable($stdout, function ($stream, $reactor) use ($processName, $io, $output) {
                            // Get process data
                            $data = fgets($stream);
                            if ($data === $io->get('contentTerminator') . PHP_EOL) {
                                // return stored, one less stream to listen to
                                $reactor->remove($stream);
                            } elseif ($data !== false) {
                                // store process output in the "processIO" collection
                                $output->set(unserialize(base64_decode($data)), $processName);
                            }
                        });
                        // When all processes done, loop terminates itself
                        // check max_execution_time to force process termination
                        $maxExecutionTime = ini_get('max_execution_time');
                        if ($maxExecutionTime > 0) {
                            // 1 ms before script timeout check if process running
                            $reactor->addTimer(
                                $maxExecutionTime - 0.001,
                                function ($reactor) use ($processName, $process, $stdin, $stdout) {
                                    if (proc_get_status($process)['running']) {
                                        // do not listen anymore
                                        $message = sprintf(
                                            'Offloaded process - #%s %s - about to exceeded script execution time, ignoring STDIN, STDOUT',
                                            (int)$process,
                                            $processName
                                        );
                                        trigger_error($message, E_USER_WARNING);
                                        $reactor->remove($stdin);
                                        $reactor->remove($stdout);
                                    }
                                },
                                false
                            );
                        }
                    }
                    $reactor->run();
                    return $output;
                }

                public function cleanUp(): void
                {
                    foreach ($this->processes as $nsProcess => [$process, $stdin, $stdout, $stderr]) {
                        // Always signal to terminate and close process resources 
                        $this->terminateProcess($process);
                        $this->closeProcess($process);
                    }
                }
            },
        ];

        parent::__construct();
    }
}
