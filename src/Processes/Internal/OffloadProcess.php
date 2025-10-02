<?php

/*
 * The MIT License
 *
 * Copyright 2024 rsousa <rmbsousa@gmail.com>.
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */

declare(strict_types=1);

namespace Flows\Processes\Internal;

use Collectibles\Collection;
use Collectibles\Contracts\IO;
use Flows\Contracts\Task;
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
                        throw new RuntimeException('Could not change ot OffloadedProcess.php script directory');
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
                        // store process data for further use
                        $processes[$processName] = [$process, $pipes[0], $pipes[1], $pipes[2]];
                    }
                    return new OffloadedIO($processes, $io->get('processIO'), contentTerminator: $io->get('contentTerminator'));
                }

                public function cleanUp(): void {}
            },
            new class implements Task {
                public function __invoke(?IO $io = null): ?IO
                {
                    $processReturn = new Collection(null);
                    $reactor = new Reactor();
                    // wait / read process output
                    foreach ($io->get('processes') as $processName => [$process, $stdin, $stdout, $stderr]) {
                        $reactor->onWritable($stdin, function ($stream, $reactor) use ($processName, $io) {
                            // send work to command
                            $message = sprintf(
                                '%s|%s|%s' . PHP_EOL,
                                $processName,
                                $io->get('contentTerminator'),
                                base64_encode(serialize($io->get('processIO')))
                            );
                            if (!fwrite($stream, $message)) {
                                throw new RuntimeException("Could not pipe process setup to child process $processName");
                            }
                            // setup piped, one less stream to write to
                            $reactor->remove($stream);
                        });
                        $reactor->onReadable($stdout, function ($stream, $reactor) use ($processName, $io, $processReturn) {
                            // get process data
                            $data = fgets($stream);
                            if ($data === $io->get('contentTerminator') . PHP_EOL) {
                                // return stored, one less stream to listen to
                                $reactor->remove($stream);
                            } elseif ($data !== false) {
                                // store process output
                                $processReturn->set(unserialize(base64_decode($data)), $processName);
                            }
                        });
                        // when all processes done, loop terminates itself
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
                    return $processReturn->set($io->get('processes'), 'processes');
                }

                public function cleanUp(): void {}
            },
            new class implements Task {
                use OffloadedProcess;
                public function __invoke(?IO $io = null): ?IO
                {
                    foreach ($io->get('processes') as $processName => [$process, $stdin, $stdout, $stderr]) {
                        // stop, remove process
                        $message = sprintf(
                            'Terminate and close offloaded process - #%s %s',
                            (int)$process,
                            $processName
                        );
                        trigger_error($message, E_USER_NOTICE);
                        $this->terminateProcess($process);
                        $this->closeProcess($process);
                    }
                    return $io;
                }

                public function cleanUp(): void {}
            },
        ];

        return parent::__construct();
    }
}
