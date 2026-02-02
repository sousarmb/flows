<?php

declare(strict_types=1);

namespace Flows\Processes\Internal\CLI\Create\Gate;

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

class EventProcess extends CLICommand
{
    protected string $help = 'Create a named gate event class in App/Events';
    protected array $arguments = [
        'name' => '=[name] Gate event class name',
        'type' => '=[frequent|http|stream]'
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
                            throw new InvalidArgumentException('Invalid string for gate event class name');
                        } elseif ($this->classFileExists($name, 'gateevent', $io->getScaffoldDestinationDirectory())) {
                            throw new InvalidArgumentException('Gate event class file exists');
                        }

                        return $io;
                    }
                    // Query the user
                    $tryAgain = true;
                    do {
                        $name = readline('Gate class name? ');
                        if (!$this->classNameIsValid($name)) {
                            echo PHP_EOL . 'Invalid string for gate event class name' . PHP_EOL;
                        } elseif ($this->classFileExists($name, 'gateevent', $io->getScaffoldDestinationDirectory())) {
                            echo PHP_EOL . 'Gate event class file exists' . PHP_EOL;
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
                 * @param IOContract|CLICollection|null $io
                 * @return IOContract|null
                 */
                public function __invoke(?IOContract $io = null): ?IOContract
                {
                    if ($type = $io->get('argv.type')) {
                        if (!in_array($type, ['frequent', 'http', 'stream'])) {
                            throw new InvalidArgumentException('Gate event type is: frequent|http|stream');
                        }

                        return $io;
                    }
                    // Query the user
                    $tryAgain = true;
                    do {
                        $type = readline('Gate event type is frequent|http|stream? ');
                        if (!in_array($type, ['frequent', 'http', 'stream'])) {
                            echo PHP_EOL . 'Invalid gate event type ' . PHP_EOL;
                        } else {
                            $io->add($type, 'argv.type');
                            $tryAgain = false;
                        }
                    } while ($tryAgain);
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
                    $fileContents = file_get_contents($io->getScaffoldTemplatesDirectory() . "{$ds}gate.event.event.{$io->get('argv.type')}.php.template");
                    $fileContents = str_replace(
                        '<!--name-->',
                        $io->get('argv.name'),
                        $fileContents
                    );
                    $ioFile = sprintf(
                        "%sProcesses{$ds}Gates{$ds}%sGate.php",
                        $io->getScaffoldDestinationDirectory(),
                        $io->get('argv.name')
                    );
                    if (false === file_put_contents($ioFile, $fileContents)) {
                        throw new RuntimeException("Could not write file {$ioFile}");
                    }

                    return new CommandOutput(
                        "Task created successfully [{$ioFile}]",
                        true
                    );
                }

                public function cleanUp(bool $forSerialization = false): void {}
            }
        ];
        parent::__construct();
    }
}
