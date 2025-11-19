<?php

declare(strict_types=1);

namespace Flows\Helpers;

use Monolog\Handler\AbstractHandler;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * Handler for a spawned/child process.
 * Every time Monolog sends a record, this handler notifies main process
 * something happened
 */
class StdErrMonologHandler extends AbstractHandler
{
    public function __construct(
        int|string|Level $level = Level::Debug,
        bool $bubble = true
    ) {
        parent::__construct($level, $bubble);
    }

    /**
     * 
     * Notify the main process something happened using STDERR (which 
     * it should be listening to inside the reactor)
     */
    protected function write(LogRecord $record): void
    {
        $signal = [
            'name' => OFFLOADED_PROCESS_NAME,
            'pid' => getmypid(),
            'level' => $record['level'],
            'when' => $record['datetime']
        ];
        file_put_contents(
            'php://stderr',
            json_encode($signal) . PHP_EOL
        );
        file_put_contents(
            'php://stderr',
            OFFLOADED_PROCESS_CONTENT_TERMINATOR . PHP_EOL
        );
    }

    public function handle(LogRecord $record): bool
    {
        $this->write($record);
        // Always bubble 
        return false;
    }

    public function isHandling(LogRecord $record): bool
    {
        return true;
    }
}
