<?php

declare(strict_types=1);

namespace Flows\Traits;

use Flows\Facades\Config;

trait Files
{
    /**
     * Stop thread until file exists.
     * Use cases:
     * - HTTP handler server starts and creates command socket file
     * 
     * @param string The file path
     */
    public function waitForFile(string $file): void
    {
        $c = Config::getApplicationSettings()->get('wait_timeout_for_files', 1000);
        while ((bool)$c && !file_exists($file)) {
            usleep(1000);
            $c--;
        }
    }
}
