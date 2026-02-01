<?php

declare(strict_types=1);

namespace Flows\Traits;

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
        $c = 1000;
        while (!file_exists($file) || (bool)$c) {
            usleep(1000);
            $c--;
        }
    }
}
