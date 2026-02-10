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

class EventProcess extends CLICommand
{
    protected string $help = 'Create and register a named event and event handler in App/Events';
    protected array $arguments = [
        'name' => '=[name] Event class name',
        'timing' => '=[realtime|defer_process|defer_flow] Event handling is realtime or deferred, from process or flow'
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
                            throw new InvalidArgumentException('Invalid string for event class name');
                        } elseif ($this->classFileExists($name, 'event', $io->getScaffoldDestinationDirectory())) {
                            throw new InvalidArgumentException('Event class file exists');
                        } elseif ($this->classFileExists($name, 'eventhandler', $io->getScaffoldDestinationDirectory())) {
                            throw new InvalidArgumentException('Event class handler class file exists');
                        }

                        return $io;
                    }
                    // Query the user
                    $tryAgain = true;
                    do {
                        $name = readline('Event class name? ');
                        if (!$this->classNameIsValid($name)) {
                            echo PHP_EOL . 'Invalid string for event class name' . PHP_EOL;
                        } elseif ($this->classFileExists($name, 'event', $io->getScaffoldDestinationDirectory())) {
                            echo PHP_EOL . 'Event class file exists' . PHP_EOL;
                        } elseif ($this->classFileExists($name, 'eventhandler', $io->getScaffoldDestinationDirectory())) {
                            echo PHP_EOL . 'Event class handler file exists' . PHP_EOL;
                        } else {
                            $tryAgain = false;
                        }
                    } while ($tryAgain);
                    return $io->set($name, 'argv.name');
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
                    $fileContents = file_get_contents($io->getScaffoldTemplatesDirectory() . "{$ds}event.php.template");
                    $fileContents = str_replace(
                        '<!--name-->',
                        $io->get('argv.name'),
                        $fileContents
                    );
                    $eventFile = sprintf(
                        "%sEvents{$ds}%sEvent.php",
                        $io->getScaffoldDestinationDirectory(),
                        $io->get('argv.name')
                    );
                    if (false === file_put_contents($eventFile, $fileContents)) {
                        throw new RuntimeException("Could not write file {$eventFile}");
                    }

                    return $io->set($eventFile, 'neweventfile');
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
                    $fileContents = file_get_contents($io->getScaffoldTemplatesDirectory() . "{$ds}eventhandler.php.template");
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
                        '<!--name-->',
                        $io->get('argv.name'),
                        $fileContents
                    );
                    $handlerFile = sprintf(
                        "%sEvents{$ds}Handlers{$ds}%sEventHandler.php",
                        $io->getScaffoldDestinationDirectory(),
                        $io->get('argv.name')
                    );
                    if (false === file_put_contents($handlerFile, $fileContents)) {
                        throw new RuntimeException("Could not write file {$handlerFile}");
                    }

                    return $io->set($handlerFile, 'newhandlerfile');
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
                    $eventHandlerMapFile = $io->getScaffoldDestinationDirectory() . "Config{$ds}event-handler.php";
                    $map = require_once $eventHandlerMapFile;
                    // Add to map
                    $nsEvent = sprintf("App\\Events\\%sEvent::class", $io->get('argv.name'));
                    $nsEventHandler = sprintf("App\\Events\\Handlers\\%sEventHandler::class", $io->get('argv.name'));
                    $map[$nsEvent] = $nsEventHandler;
                    // Render map
                    $templateMapFile = $io->getScaffoldSourceDirectory() . "Config{$ds}event-handler.php";
                    $template = file_get_contents($templateMapFile);
                    $textMap = '';
                    foreach ($map as $nsEvent => $nsEventHandler) {
                        if (false === strrpos($nsEvent, '::class')) {
                            $nsEvent .= '::class';
                            $nsEventHandler .= '::class';
                        }

                        $textMap .= "\t{$nsEvent} => {$nsEventHandler}," . PHP_EOL;
                    }
                    $find = "return [];";
                    $replace = sprintf('return [%s%s];', PHP_EOL, $textMap);
                    $contents = str_replace($find, $replace, $template);
                    if (false === file_put_contents($eventHandlerMapFile, $contents)) {
                        throw new RuntimeException('Could not register new event to configuration file');
                    }

                    $filesWritten = [
                        '', // new line (lazy, i know)
                        " Registration: {$eventHandlerMapFile}",
                        " Event file: {$io->get('neweventfile')}",
                        " Handler file: {$io->get('newhandlerfile')}",
                        '',
                    ];
                    return new CommandOutput(
                        'Event created successfully [' . implode(PHP_EOL, $filesWritten) . ']',
                        true
                    );
                }

                public function cleanUp(bool $forSerialization = false): void {}
            }
        ];
        parent::__construct();
    }
}
