<?php

declare(strict_types=1);

namespace Flows\Traits;

trait ClassChecker
{
    const VALID_CLASS_REGEX = "/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*$/";

    /**
     * Check if string can be used as a class name
     * 
     * @param string $name
     * @return bool TRUE => valid, FALSE => not valid
     */
    public function classNameIsValid(string $name): bool
    {
        return 1 === preg_match(self::VALID_CLASS_REGEX, $name);
    }

    /**
     * Check if class file exists
     * 
     * @param string $name
     * @param string $component 'event'|'eventhandler'|'process'|'task'
     * @param string $baseDirectory
     * @return bool TRUE => file exists, FALSE => file does not exist
     */
    public function classFileExists(
        string $name,
        string $component,
        string $baseDirectory
    ): bool {
        $ds = DIRECTORY_SEPARATOR;
        $pattern = match ($component) {
            'contract' => "%sServices{$ds}Contracts{$ds}%sContract.php",
            'event' => "%sEvents{$ds}%sEvent.php",
            'eventhandler' => "%sEvents{$ds}Handlers{$ds}%sEvent.php",
            'gate' => "%sProcesses{$ds}Gates{$ds}%sGate.php",
            'gateevent' => "%sEvents{$ds}%sGateEvent.php",
            'io' => "%sProcesses{$ds}IO{$ds}%sIO.php",
            'observer' => "%sObservers{$ds}%sObserver.php",
            'process' => "%sProcesses{$ds}%sProcess.php",
            'service' => "%sServices{$ds}%sService.php",
            'serviceprovider' => "%sServices{$ds}Providers{$ds}%sServiceProvider.php",
            'task' => "%sProcesses{$ds}Tasks{$ds}%sTask.php",
        };

        $file = sprintf(
            $pattern,
            $baseDirectory,
            $name
        );
        return file_exists($file);
    }
}
