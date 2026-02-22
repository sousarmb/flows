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

class TaskProcess extends CLICommand
{
    protected string $help = 'Create a named task in App/Processes/Tasks';
    protected array $arguments = [
        'name' => '=[name] Task class name',
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
                 * @throws InvalidArgumentException If invalid string for task class name or task class file exists
                 * @return Collection|IO|null
                 */
                public function __invoke(CLICollection|Collection|IO|null $io = null): Collection|IO|null
                {
                    echo PHP_EOL . 'Valid class names must obey regex ' . self::VALID_CLASS_REGEX . PHP_EOL . PHP_EOL;
                    $name = $io->get('argv.name');
                    if ($name) {
                        if (!$this->classNameIsValid($name)) {
                            throw new InvalidArgumentException('Invalid string for task class name');
                        } elseif ($this->classFileExists($name, 'task', $io->getScaffoldDestinationDirectory())) {
                            throw new InvalidArgumentException('Task class file exists');
                        }

                        return $io;
                    }
                    // Query the user
                    $tryAgain = true;
                    do {
                        $name = readline('Task class name? ');
                        if (!$this->classNameIsValid($name)) {
                            echo PHP_EOL . 'Invalid string for task class name' . PHP_EOL;
                        } elseif ($this->classFileExists($name, 'task', $io->getScaffoldDestinationDirectory())) {
                            echo PHP_EOL . 'Task class file exists' . PHP_EOL;
                        } else {
                            $tryAgain = false;
                        }
                    } while ($tryAgain);
                    return $io->set($name, 'argv.name');
                }

                public function cleanUp(bool $forSerialization = false): void {}
            },
            new class implements TaskContract {
                /**
                 * @param CLICollection|Collection|IO|null $io
                 * @throws RuntimeException If unable to write task class file
                 * @return CommandOutput|Collection|IO|null
                 */
                public function __invoke(CLICollection|Collection|IO|null $io = null): CommandOutput|Collection|IO|null
                {
                    $ds = DIRECTORY_SEPARATOR;
                    $fileContents = file_get_contents($io->getScaffoldTemplatesDirectory() . "{$ds}task.php.template");
                    $fileContents = str_replace(
                        '<!--name-->',
                        $io->get('argv.name'),
                        $fileContents
                    );
                    $taskFile = sprintf(
                        "%sProcesses{$ds}Tasks{$ds}%sTask.php",
                        $io->getScaffoldDestinationDirectory(),
                        $io->get('argv.name')
                    );
                    if (false === file_put_contents($taskFile, $fileContents)) {
                        throw new RuntimeException("Could not write file {$taskFile}");
                    }

                    return new CommandOutput(
                        "Task created successfully [{$taskFile}]",
                        true
                    );
                }

                public function cleanUp(bool $forSerialization = false): void {}
            }
        ];
        parent::__construct();
    }
}
