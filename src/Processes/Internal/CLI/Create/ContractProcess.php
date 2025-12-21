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

class ContractProcess extends CLICommand
{
    protected string $help = 'Create a named interface class in App/Services/Contracts';
    protected array $arguments = [
        'name' => '=[name] Interface class name',
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
                            throw new InvalidArgumentException('Invalid string for interface class name');
                        } elseif ($this->classFileExists($name, 'task', $io->getScaffoldDestinationDirectory())) {
                            throw new InvalidArgumentException('Interface class file exists');
                        }

                        return $io;
                    }
                    // Query the user
                    $tryAgain = true;
                    do {
                        $name = readline('Interface class name? ');
                        if (!$this->classNameIsValid($name)) {
                            echo PHP_EOL . 'Invalid string for interface class name' . PHP_EOL;
                        } elseif ($this->classFileExists($name, 'contract', $io->getScaffoldDestinationDirectory())) {
                            echo PHP_EOL . 'Interface class file exists' . PHP_EOL;
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
                    $ds = DIRECTORY_SEPARATOR;
                    $fileContents = file_get_contents($io->getScaffoldTemplatesDirectory() . "{$ds}contract.php.template");
                    $fileContents = str_replace(
                        '<!--name-->',
                        $io->get('argv.name'),
                        $fileContents
                    );
                    $contractFile = sprintf(
                        "%sServices{$ds}Contracts{$ds}%sContract.php",
                        $io->getScaffoldDestinationDirectory(),
                        $io->get('argv.name')
                    );
                    if (false === file_put_contents($contractFile, $fileContents)) {
                        throw new RuntimeException("Could not write file {$contractFile}");
                    }

                    return new CommandOutput(
                        "Contract created successfully [{$contractFile}]",
                        true
                    );
                }

                public function cleanUp(bool $forSerialization = false): void {}
            }
        ];
        parent::__construct();
    }
}
