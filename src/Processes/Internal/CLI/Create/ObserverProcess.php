<?php

declare(strict_types=1);

namespace Flows\Processes\Internal\CLI\Create;

use Collectibles\Contracts\IO as IOContract;
use Flows\Contracts\Tasks\Task as TaskContract;
use Flows\Processes\CLICommand;
use Flows\Processes\Internal\CLI\CheckAppScaffoldExistsTask;
use Flows\Processes\Internal\CLI\CheckForCommandFlagTask;
use Flows\Processes\Internal\CLI\SetTimingTask;
use Flows\Processes\Internal\IO\CLICollection;
use Flows\Processes\Internal\IO\CommandOutput;
use Flows\Traits\ClassChecker;
use InvalidArgumentException;
use RuntimeException;

class ObserverProcess extends CLICommand
{
    protected string $help = 'Create and register a named observer in App/Observers. Processes, gates and IO instances may be observed';
    protected array $arguments = [
        'name' => '=[name] Observer class name',
        'subjecttype' => '=[gate|io|process] Subject type to observe',
        'subjectname' => '=[name] Subject class name',
        'timing' => '=[realtime|defer_process|defer_flow] Observation occurs in realtime or deferred, from process or flow'
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
                            throw new InvalidArgumentException('Invalid string for observer class name');
                        } elseif ($this->classFileExists($name, 'observer', $io->getScaffoldDestinationDirectory())) {
                            throw new InvalidArgumentException('Observer class file exists');
                        }

                        return $io;
                    }
                    // Query the user
                    $tryAgain = true;
                    do {
                        $name = readline('Event class name? ');
                        if (!$this->classNameIsValid($name)) {
                            echo PHP_EOL . 'Invalid string for observer class name' . PHP_EOL;
                        } elseif ($this->classFileExists($name, 'observer', $io->getScaffoldDestinationDirectory())) {
                            echo PHP_EOL . 'Observer class file exists' . PHP_EOL;
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
                    if ($subjectType = $io->get('argv.subjecttype')) {
                        if (!in_array($subjectType, ['gate', 'io', 'process'])) {
                            throw new InvalidArgumentException('Subject type is: gate|io|process');
                        }

                        return $io;
                    }
                    // Query the user
                    $tryAgain = true;
                    do {
                        $subjectType = readline('Subject type to observe is gate|io|process? ');
                        if (!in_array($subjectType, ['gate', 'io', 'process'])) {
                            echo PHP_EOL . 'Invalid subject type ' . PHP_EOL;
                        } else {
                            $io->add($subjectType, 'argv.subjecttype');
                            $tryAgain = false;
                        }
                    } while ($tryAgain);
                    return $io;
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
                    $subjectName = $io->get('argv.subjectname');
                    if ($subjectName) {
                        if (!$this->classNameIsValid($subjectName)) {
                            throw new InvalidArgumentException('Invalid string for subject class name');
                        } elseif (!$this->classFileExists($subjectName, $io->get('argv.subjecttype'), $io->getScaffoldDestinationDirectory())) {
                            throw new InvalidArgumentException('Subject class file does not exist');
                        }

                        return $io;
                    }
                    // Query the user
                    $tryAgain = true;
                    do {
                        $subjectName = readline('Subject class name? ');
                        if (!$this->classNameIsValid($subjectName)) {
                            echo PHP_EOL . 'Invalid string for subject class name' . PHP_EOL;
                        } elseif (!$this->classFileExists($subjectName, $io->get('argv.subjecttype'), $io->getScaffoldDestinationDirectory())) {
                            echo PHP_EOL . 'Subject class file does not exist' . PHP_EOL;
                        } else {
                            $tryAgain = false;
                        }
                    } while ($tryAgain);
                    return $io->set($subjectName, 'argv.subjectname');
                }

                public function cleanUp(bool $forSerialization = false): void {}
            },
            SetTimingTask::class,
            new class implements TaskContract {
                /**
                 * @param IOContract|CLICollection|null $io
                 * @return IOContract|null
                 */
                public function __invoke(?IOContract $io = null): ?IOContract
                {
                    $ds = DIRECTORY_SEPARATOR;
                    $fileContents = file_get_contents($io->getScaffoldTemplatesDirectory() . "{$ds}observer.php.template");
                    $timing = match ($io->get('argv.timing')) {
                        'realtime' => 'use Flows\Attributes\Realtime;',
                        'defer_process' => 'use Flows\Attributes\Defer\DeferFromProcess;',
                        'defer_flow' => 'use Flows\Attributes\Defer\DeferFromFlow;',
                    };
                    $fileContents = str_replace(
                        '<!--use-attributes-list-->',
                        $timing,
                        $fileContents
                    );
                    $timing = match ($io->get('argv.timing')) {
                        'realtime' => '#[Realtime()]',
                        'defer_process' => '#[DeferFromProcess()]',
                        'defer_flow' => '#[DeferFromFlow()]',
                    };
                    $fileContents = str_replace(
                        '<!--handle-timing-attribute-->',
                        $timing,
                        $fileContents
                    );
                    $fileContents = str_replace(
                        '<!--observer-name-->',
                        $io->get('argv.name'),
                        $fileContents
                    );
                    $observerFile = sprintf(
                        "%sObservers{$ds}%sObserver.php",
                        $io->getScaffoldDestinationDirectory(),
                        $io->get('argv.name')
                    );
                    if (false === file_put_contents($observerFile, $fileContents)) {
                        throw new RuntimeException("Could not write file {$observerFile}");
                    }

                    return $io->set($observerFile, 'newobserverfile');
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
                    $subjectObserverMapFile = $io->getScaffoldDestinationDirectory() . "Config{$ds}subject-observer.php";
                    $map = require_once $subjectObserverMapFile;
                    // Add to map
                    $subjectTypeDirectory = match ($io->get('argv.subjecttype')) {
                        'gate' => 'Gates',
                        'io' => 'IO',
                        'process' => 'Processes'
                    };
                    $subjectType = match ($io->get('argv.subjecttype')) {
                        'gate' => 'Gate',
                        'io' => 'IO',
                        'process' => 'Process'
                    };
                    $nsSubject = sprintf("App\\%s\\%s%s::class", $subjectTypeDirectory, $io->get('argv.subjectname'), $subjectType);
                    $nsObserver = sprintf("App\\Observers\\%sObserver::class", $io->get('argv.name'));
                    $map[$nsSubject] = $nsObserver;
                    // Render map
                    $templateMapFile = $io->getScaffoldSourceDirectory() . "Config{$ds}subject-observer.php";
                    $template = file_get_contents($templateMapFile);
                    $textMap = '';
                    foreach ($map as $nsSubject => $nsObserver) {
                        if (false === strrpos($nsSubject, '::class')) {
                            $nsSubject .= '::class';
                            $nsObserver .= '::class';
                        }

                        $textMap .= "\t{$nsSubject} => {$nsObserver}," . PHP_EOL;
                    }
                    $find = "return [];";
                    $replace = sprintf('return [%s%s];', PHP_EOL, $textMap);
                    $contents = str_replace($find, $replace, $template);
                    if (false === file_put_contents($subjectObserverMapFile, $contents)) {
                        throw new RuntimeException('Could not register new observer to configuration file');
                    }

                    $filesWritten = [
                        $subjectObserverMapFile,
                        $io->get('newobserverfile')
                    ];
                    return new CommandOutput(
                        'Observer created successfully [' . implode(', ', $filesWritten) . ']',
                        true
                    );
                }

                public function cleanUp(bool $forSerialization = false): void {}
            }
        ];
        parent::__construct();
    }
}
