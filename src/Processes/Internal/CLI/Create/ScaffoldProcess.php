<?php

declare(strict_types=1);

namespace Flows\Processes\Internal\CLI\Create;

use Collectibles\Contracts\IO as IOContract;
use Flows\Contracts\Tasks\Task as TaskContract;
use Flows\Processes\CLICommand;
use Flows\Processes\Internal\CLI\CheckForCommandFlagTask;
use Flows\Processes\Internal\IO\CLICollection;
use Flows\Processes\Internal\IO\CommandOutput;
use InvalidArgumentException;
use LogicException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

class ScaffoldProcess extends CLICommand
{
    protected string $help = 'Create application scaffold (necessary configuration files and directories to run an flows application)';

    public function __construct()
    {
        $this->tasks = [
            CheckForCommandFlagTask::class,
            new class implements TaskContract {
                /**
                 * @param IOContract|CLICollection|null $io
                 * @return IOContract|null
                 */
                public function __invoke(?IOContract $io = null): ?IOContract
                {
                    if (is_dir($io->getScaffoldDestinationDirectory())) {
                        throw new LogicException('Application directory already exists');
                    }

                    $this->copyDirectory(
                        $io->getScaffoldSourceDirectory(),
                        $io->getScaffoldDestinationDirectory()
                    );
                    return new CommandOutput(
                        'Application scaffold directories created. Update project composer.json autoload settings to include "App/" directory',
                        true
                    );
                }

                public function cleanUp(bool $forSerialization = false): void {}

                private function copyDirectory(string $source, string $destination): void
                {
                    // Normalize paths (remove trailing slashes/backslashes)
                    $source = rtrim($source, '/\\');
                    $destination = rtrim($destination, '/\\');
                    if (!is_dir($source)) {
                        throw new InvalidArgumentException("Source \"{$source}\" is not a directory");
                    }
                    // Create root destination directory if needed
                    if (!is_dir($destination)) {
                        mkdir($destination, 0755, true);
                    }

                    $directory = new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS);
                    $iterator = new RecursiveIteratorIterator($directory, RecursiveIteratorIterator::SELF_FIRST);
                    /** @var SplFileInfo $item */
                    foreach ($iterator as $item) {
                        $subPathName = $iterator->getSubIterator()->getSubPathname();
                        $targetPath = $destination . DIRECTORY_SEPARATOR . $subPathName;
                        if ($item->isDir()) {
                            // Create subdirectory (recursive mkdir handles nesting)
                            if (!mkdir($targetPath, 0755, true)) {
                                throw new RuntimeException("Could not create directory $targetPath");
                            }
                        } else {
                            // Copy the file
                            if (!copy($item->getRealPath(), $targetPath)) {
                                throw new RuntimeException("Could not create file $targetPath");
                            }
                        }
                    }
                }
            }
        ];
        parent::__construct();
    }
}
