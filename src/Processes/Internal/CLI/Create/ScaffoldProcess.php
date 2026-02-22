<?php

declare(strict_types=1);

namespace Flows\Processes\Internal\CLI\Create;

use Collectibles\Collection;
use Collectibles\IO;
use Flows\Contracts\Tasks\Task as TaskContract;
use Flows\Processes\CLICommand;
use Flows\Processes\Internal\CLI\CheckForCommandFlagTask;
use Flows\Processes\Internal\IO\CLICollection;
use Flows\Processes\Internal\IO\CommandOutput;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

class ScaffoldProcess extends CLICommand
{
    protected string $help = 'Create application scaffold (necessary configuration files and directories to run an flows application)';

    public function __construct()
    {
        $this->tasks = [
            CheckForCommandFlagTask::class,
            new class implements TaskContract {
                /**
                 * @param CLICollection|Collection|IO|null $io
                 * @return CommandOutput|Collection|IO|null
                 */
                public function __invoke(CLICollection|Collection|IO|null $io = null): CommandOutput|Collection|IO|null
                {
                    if (is_dir($io->getScaffoldDestinationDirectory())) {
                        return new CommandOutput(
                            'Application scaffold already exists',
                            false
                        );
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

                /**
                 * Recursively copy a directory and its contents to a new location
                 * Note: This method assumes that the source directory exists and is readable, and that the destination directory is writable or can be created. It does not handle symbolic links or special file types.
                 * 
                 * @param string $source The path of the source directory
                 * @param string $destination The path of the destination directory
                 * @throws InvalidArgumentException If source is not a directory
                 * @throws RuntimeException If unable to create a directory or copy a file
                 */
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
