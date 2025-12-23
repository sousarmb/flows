<?php

declare(strict_types=1);

namespace Flows\Processes\Internal\CLI\Create;

use Collectibles\Contracts\IO as IOContract;
use Flows\Contracts\Tasks\Task as TaskContract;
use Flows\Processes\CLICommand;
use Flows\Processes\Internal\CLI\CheckAppScaffoldExistsTask;
use Flows\Processes\Internal\CLI\CheckForCommandFlagTask;
use Flows\Processes\Internal\IO\CLICollection;
use Flows\Processes\Internal\IO\CommandOutput;
use Flows\Traits\ClassChecker;
use InvalidArgumentException;
use RuntimeException;

class ProcessProcess extends CLICommand
{
    protected string $help = 'Create a named process in App/Processes';
    protected array $arguments = [
        'name' => '=[name] Process class name',
        'tasks' => '=[task1,task2] Task list to be included in the new process (comma separated names)'
    ];

    public function __construct()
    {
        $this->tasks = [
            CheckForCommandFlagTask::class,
            CheckAppScaffoldExistsTask::class,
            new class implements TaskContract {
                use ClassChecker;
                /**
                 * @param IOContract|CLICollection|null $io
                 * @return IOContract|null
                 */
                public function __invoke(?IOContract $io = null): ?IOContract
                {
                    echo PHP_EOL . 'Valid class names must obey regex ' . self::VALID_CLASS_REGEX . PHP_EOL . PHP_EOL;
                    $name = $io->get('argv.name');
                    if ($name) {
                        if (!$this->classNameIsValid($name)) {
                            throw new InvalidArgumentException('Invalid string for process class name');
                        } elseif ($this->classFileExists($name, 'process', $io->getScaffoldDestinationDirectory())) {
                            throw new InvalidArgumentException('Process class file exists');
                        }

                        return $io;
                    }
                    // Query the user
                    $tryAgain = true;
                    do {
                        $name = readline('Process class name? ');
                        if (!$this->classNameIsValid($name)) {
                            echo PHP_EOL . 'Invalid string for process class name' . PHP_EOL;
                        } elseif ($this->classFileExists($name, 'process', $io->getScaffoldDestinationDirectory())) {
                            echo PHP_EOL . 'Process class file exists' . PHP_EOL;
                        } else {
                            $tryAgain = false;
                        }
                    } while ($tryAgain);
                    return $io->set($name, 'argv.name');
                }

                public function cleanUp(bool $forSerialization = false): void {}
            },
            new class implements TaskContract {
                use ClassChecker;
                /**
                 * @param IOContract|CLICollection|null $io
                 * @return IOContract|null
                 */
                public function __invoke(?IOContract $io = null): ?IOContract
                {
                    if ($tasks = $io->get('argv.tasks')) {
                        $temp = [];
                        foreach (explode(',', $tasks) as $taskName) {
                            if (!$this->classNameIsValid($taskName)) {
                                throw new InvalidArgumentException('Invalid string for task class name');
                            } elseif ($this->classFileExists($taskName, 'task', $io->getScaffoldDestinationDirectory())) {
                                throw new InvalidArgumentException('Task class file exists');
                            } else {
                                $temp[] = $taskName;
                            }
                        }
                        $io->set($temp, 'argv.tasks');
                        return $io;
                    }
                    // Query the user
                    $another = true;
                    echo PHP_EOL . 'Add process tasks?' . PHP_EOL;
                    do {
                        $taskName = readline('Add task named (or "---" to stop): ');
                        if ($taskName === '---') {
                            $another = false;
                        } elseif (!$this->classNameIsValid($taskName)) {
                            echo PHP_EOL . 'Invalid string for task class name' . PHP_EOL;
                        } elseif ($this->classFileExists($taskName, 'task', $io->getScaffoldDestinationDirectory())) {
                            throw PHP_EOL . 'Task class file exists' . PHP_EOL;
                        } else {
                            $io->add("{$taskName}Task", 'argv.tasks');
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
                        if (is_string($argvTasks)) {
                            $argvTasks = [$argvTasks];
                        }

                        $useList = $taskList = '';
                        foreach ($argvTasks as $task) {
                            $useList .= "use App\\Processes\\Tasks\\{$task};" . PHP_EOL;
                            $taskList .= "\t\t\t{$task}::class," . PHP_EOL;
                        }
                        $fileContents = str_replace(
                            ['<!--use-list-->', '<!--task-list-->'],
                            [$useList, $taskList],
                            $fileContents
                        );
                    } else {
                        $fileContents = str_replace(
                            ['<!--use-list-->', '<!--task-list-->'],
                            '',
                            $fileContents
                        );
                    }

                    $fileContents = str_replace(
                        '<!--name-->',
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
                        "Process created successfully [{$processFile}]",
                        true
                    );
                }

                public function cleanUp(bool $forSerialization = false): void {}
            }
        ];
        parent::__construct();
    }
}
