<?php

declare(strict_types=1);

namespace Flows\Processes\Internal\CLI\Create;

use Collectibles\Contracts\IO as IOContract;
use Flows\Contracts\Tasks\Task as TaskContract;
use Flows\Processes\CLICommand;
use Flows\Processes\Internal\CLI\CheckAppScaffoldExists;
use Flows\Processes\Internal\CLI\CheckForCommandFlagTask;
use Flows\Processes\Internal\IO\CLICollection;
use Flows\Processes\Internal\IO\CommandOutput;
use Flows\Traits\ClassNameChecker;
use InvalidArgumentException;
use RuntimeException;

class ProcessProcess extends CLICommand
{
    protected string $help = 'Create a new named process in the application processes directory.';

    protected array $arguments = [
        'name' => 'Process name',
        'tasks' => 'Task list to be created (or included) in the new process (comma separated names)'
    ];

    public function __construct()
    {
        $this->tasks = [
            CheckForCommandFlagTask::class,
            CheckAppScaffoldExists::class,
            new class implements TaskContract {
                use ClassNameChecker;
                /**
                 * @param IOContract|CLICollection|null $io
                 * @return IOContract|null
                 */
                public function __invoke(?IOContract $io = null): ?IOContract
                {
                    echo PHP_EOL . 'Valid class names must obey regex ' . self::VALID_CLASS_REGEX . PHP_EOL . PHP_EOL;
                    $processName = $io->get('argv.name');
                    if ($processName) {
                        if (!$this->isValid($processName)) {
                            throw new InvalidArgumentException('Invalid string for process class name');
                        } elseif ($this->processFileExists($io, $processName)) {
                            throw new InvalidArgumentException('This process class name is already used');
                        }
                        // Proceed to tasks
                        return $io;
                    }
                    // Query the user
                    $tryAgain = true;
                    do {
                        $processName = readline('Process class name? ');
                        if (!$this->isValid($processName)) {
                            echo PHP_EOL . 'Invalid string for process class name' . PHP_EOL;
                        } elseif ($this->processFileExists($io, $processName)) {
                            echo PHP_EOL . 'This process class name is already used' . PHP_EOL;
                        } else {
                            $tryAgain = false;
                        }
                    } while ($tryAgain);
                    return $io->set($processName, 'argv.name');
                }

                private function processFileExists(CLICollection $io, string $processName): bool
                {
                    $processFile = sprintf(
                        '%sProcesses' . DIRECTORY_SEPARATOR . '%sProcess.php',
                        $io->getScaffoldDestinationDirectory(),
                        $processName
                    );
                    return file_exists($processFile);
                }

                public function cleanUp(bool $forSerialization = false): void {}
            },
            new class implements TaskContract {
                use ClassNameChecker;
                /**
                 * @param IOContract|CLICollection|null $io
                 * @return IOContract|null
                 */
                public function __invoke(?IOContract $io = null): ?IOContract
                {
                    if ($tasks = $io->get('argv.tasks')) {
                        $temp = [];
                        foreach (explode(',', $tasks) as $taskName) {
                            if (!$this->isValid($taskName)) {
                                throw new InvalidArgumentException('Invalid string for task class name');
                            } elseif (class_exists("App\\Processes\\Tasks\\{$taskName}Task", false)) {
                                throw new InvalidArgumentException('This task class name is already used');
                            } else {
                                $temp[] = $taskName;
                            }
                        }
                        $io->set($temp, 'argv.tasks');
                        // Proceed to create
                        return $io;
                    }
                    // Query the user
                    $another = true;
                    echo PHP_EOL . 'Add process tasks?' . PHP_EOL;
                    do {
                        $taskName = readline('Add task named (or "---" to stop): ');
                        if ($taskName === '---') {
                            $another = false;
                        } elseif (!$this->isValid($taskName)) {
                            echo PHP_EOL . 'Invalid string for task class name' . PHP_EOL;
                        } elseif (class_exists("App\\Processes\\Tasks\\{$taskName}Task", false)) {
                            throw PHP_EOL . 'This task class name is already used' . PHP_EOL;
                        } else {
                            $io->add($taskName, 'argv.tasks');
                        }
                    } while ($another);
                    return $io;
                }

                public function cleanUp(bool $forSerialization = false): void {}
            },
            new class implements TaskContract {
                /**
                 * @param IOContract|CLICollection|null $io
                 * @return IOContract|null
                 */
                public function __invoke(?IOContract $io = null): ?IOContract
                {
                    $ds = DIRECTORY_SEPARATOR;
                    $fileContents = file_get_contents($io->getScaffoldTemplatesDirectory() . "{$ds}process.php.template");
                    if ($io->has('argv.tasks')) {
                        $argvTasks = $io->get('argv.tasks');
                        $useList = $taskList = '';
                        foreach ($argvTasks as $task) {
                            $useList .= "use App\\Processes\\Tasks\\{$task}::class;" . PHP_EOL;
                            $taskList .= "\t\t\t{$task}::class," . PHP_EOL;
                        }
                        $fileContents = preg_replace(
                            ['/<!--use-list-->/', '/<!--task-list-->/'],
                            [$useList, $taskList],
                            $fileContents
                        );
                    } else {
                        $fileContents = preg_replace(
                            ['/<!--use-list-->/', '/<!--task-list-->/'],
                            '',
                            $fileContents
                        );
                    }

                    $fileContents = preg_replace(
                        '/<!--process-name-->/',
                        $io->get('argv.name'),
                        $fileContents
                    );
                    $processFile = sprintf(
                        "%sProcesses{$ds}%sProcess.php",
                        $io->getScaffoldDestinationDirectory(),
                        $io->get('argv.name')
                    );
                    if (false === file_put_contents($processFile, $fileContents)) {
                        throw new RuntimeException("Could not write file {$processFile}");
                    }

                    return new CommandOutput(
                        "Process created successfully {$processFile}",
                        true
                    );
                }

                public function cleanUp(bool $forSerialization = false): void {}
            }
        ];
        parent::__construct();
    }
}
