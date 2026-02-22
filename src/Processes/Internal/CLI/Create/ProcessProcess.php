<?php

declare(strict_types=1);

namespace Flows\Processes\Internal\CLI\Create;

use Collectibles\Collection;
use Collectibles\IO;
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
                 * @param CLICollection|Collection|IO|null $io
                 * @throws InvalidArgumentException If invalid string for process class name or class file exists
                 * @return Collection|IO|null
                 */
                public function __invoke(CLICollection|Collection|IO|null $io = null): Collection|IO|null
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
                 * @param CLICollection|Collection|IO|null $io
                 * @throws InvalidArgumentException If invalid string for task class name or class file exists
                 * @return Collection|IO|null
                 */
                public function __invoke(CLICollection|Collection|IO|null $io = null): Collection|IO|null
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
                            echo PHP_EOL . 'Task class file exists' . PHP_EOL;
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
                 * @param CLICollection|Collection|IO|null $io
                 * @throws RuntimeException If unable to write process or task class file
                 * @return CommandOutput|Collection|IO|null
                 */
                public function __invoke(CLICollection|Collection|IO|null $io = null): CommandOutput|Collection|IO|null
                {
                    $ds = DIRECTORY_SEPARATOR;
                    $fileContents = file_get_contents($io->getScaffoldTemplatesDirectory() . "{$ds}process.php.template");
                    $outputMessage = [];
                    if ($io->has('argv.tasks')) {
                        $argvTasks = $io->get('argv.tasks');
                        if (is_string($argvTasks)) {
                            $argvTasks = [$argvTasks];
                        }

                        $useList = $taskList = '';
                        $binDir = dirname(__FILE__, 8) . "{$ds}bin{$ds}";
                        foreach ($argvTasks as $task) {
                            $useList .= "use App\\Processes\\Tasks\\{$task};" . PHP_EOL;
                            $taskList .= "\t\t\t{$task}::class," . PHP_EOL;
                            $this->createTaskClassFile($task, $binDir, $outputMessage);
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

                    $outputMessage[] = "Process created successfully [{$processFile}]";
                    return new CommandOutput(
                        implode(PHP_EOL, $outputMessage),
                        true
                    );
                }

                private function createTaskClassFile(
                    string $task,
                    string $binDir,
                    array &$outputMessage
                ): void {
                    $ds = DIRECTORY_SEPARATOR;
                    $output = [];
                    $result = 99;
                    // Prevent double "task" suffix (already added by code generation)
                    $temp = strrpos($task, 'Task') === false
                        ? $task
                        : substr($task, 0, strrpos($task, 'Task'));
                    exec("php {$binDir}flows create:task name={$temp}", $output, $result);
                    $outputMessage[] = $result === 0 ? "{$output[8]}" : "{$output[4]}";
                }

                public function cleanUp(bool $forSerialization = false): void {}
            }
        ];
        parent::__construct();
    }
}
