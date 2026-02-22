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

class IoProcess extends CLICommand
{
    protected string $help = 'Create a named IO class in App/Processes/IO';
    protected array $arguments = [
        'name' => '=[name] IO class name',
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
                 * @throws InvalidArgumentException If Invalid string for IO class name or class file exists
                 * @return Collection|IO|null
                 */
                public function __invoke(CLICollection|Collection|IO|null $io = null): Collection|IO|null
                {
                    echo PHP_EOL . 'Valid class names must obey regex ' . self::VALID_CLASS_REGEX . PHP_EOL . PHP_EOL;
                    $name = $io->get('argv.name');
                    if ($name) {
                        if (!$this->classNameIsValid($name)) {
                            throw new InvalidArgumentException('Invalid string for IO class name');
                        } elseif ($this->classFileExists($name, 'task', $io->getScaffoldDestinationDirectory())) {
                            throw new InvalidArgumentException('IO class file exists');
                        }

                        return $io;
                    }
                    // Query the user
                    $tryAgain = true;
                    do {
                        $name = readline('IO class name? ');
                        if (!$this->classNameIsValid($name)) {
                            echo PHP_EOL . 'Invalid string for IO class name' . PHP_EOL;
                        } elseif ($this->classFileExists($name, 'task', $io->getScaffoldDestinationDirectory())) {
                            echo PHP_EOL . 'IO class file exists' . PHP_EOL;
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
                 * @throws RuntimeException If unable to write IO class file
                 * @return CommandOutput|Collection|IO|null
                 */
                public function __invoke(CLICollection|Collection|IO|null $io = null): CommandOutput|Collection|IO|null
                {
                    $ds = DIRECTORY_SEPARATOR;
                    $fileContents = file_get_contents($io->getScaffoldTemplatesDirectory() . "{$ds}io.php.template");
                    $fileContents = str_replace(
                        '<!--name-->',
                        $io->get('argv.name'),
                        $fileContents
                    );
                    $ioFile = sprintf(
                        "%sProcesses{$ds}IO{$ds}%sIO.php",
                        $io->getScaffoldDestinationDirectory(),
                        $io->get('argv.name')
                    );
                    if (false === file_put_contents($ioFile, $fileContents)) {
                        throw new RuntimeException("Could not write file {$ioFile}");
                    }

                    return new CommandOutput(
                        "DTO created successfully [{$ioFile}]",
                        true
                    );
                }

                public function cleanUp(bool $forSerialization = false): void {}
            }
        ];
        parent::__construct();
    }
}
