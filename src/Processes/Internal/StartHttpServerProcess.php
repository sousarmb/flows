<?php

declare(strict_types=1);

namespace Flows\Processes\Internal;

use Collectibles\Collection;
use Collectibles\Contracts\IO as IOContract;
use Composer\InstalledVersions;
use Flows\Contracts\Tasks\Task as TaskContract;
use Flows\Exceptions\CouldNotStartHttpServerException;
use Flows\Exceptions\HttpServerRunningException;
use Flows\Facades\Config;
use Flows\Facades\Logger;
use Flows\Processes\Process;
use RuntimeException;

class StartHttpServerProcess extends Process
{
    public function __construct()
    {
        $this->tasks = [
            new class implements TaskContract {
                /**
                 * @param IOContract|Collection|null $io
                 * @return IOContract|null
                 */
                public function __invoke(?IOContract $io = null): ?IOContract
                {
                    $commandPipe = Config::getApplicationSettings()->get('http.server.command_pipe_path');
                    if (file_exists($commandPipe)) {
                        throw new HttpServerRunningException();
                    }

                    $ds = DIRECTORY_SEPARATOR;
                    $packageDir = InstalledVersions::getInstallPath(FLOWS_PACKAGE_NAME);
                    $binDir = dirname($packageDir, 4) . "{$ds}bin";
                    if (false === chdir($binDir)) {
                        throw new RuntimeException("Could not change to HTTP server runtime directory: {$binDir}");
                    }
                    if (false === is_executable('http-server')) {
                        throw new RuntimeException('HTTP server runtime not executable');
                    }
                    if (false === posix_mkfifo($commandPipe, 0664)) {
                        throw new RuntimeException("Could not create command pipe file: {$commandPipe}");
                    }

                    return $io;
                }

                public function cleanUp(bool $forSerialization = false): void {}
            },
            new class implements TaskContract {
                /**
                 * @param IOContract|Collection|null $io
                 * @return IOContract|null
                 */
                public function __invoke(?IOContract $io = null): ?IOContract
                {
                    $descriptorSpec = [
                        0 => ['pipe', 'r'], // STDIN (write to child process)
                        1 => ['pipe', 'w'], // STDOUT (read from child process)
                        2 => ['file', Config::getLogDirectory() . 'http-server.log', 'a'], // STDERR
                    ];
                    $settings = Config::getApplicationSettings();
                    $port = $settings->get('http.server.listen_on');
                    $commandPipe = $settings->get('http.server.command_pipe_path');
                    $httpServer = proc_open(
                        ['./http-server', '--port', $port, '--command-pipe-path', $commandPipe],
                        $descriptorSpec,
                        $pipes,
                        getcwd()
                    );
                    if (false === $httpServer) {
                        throw new CouldNotStartHttpServerException();
                    }

                    sleep(1);

                    Logger::info("Started HTTP server on port {$port} with command pipe {$commandPipe}");
                    return $io;
                }

                public function cleanUp(bool $forSerialization = false): void {}
            },
        ];

        parent::__construct();
    }
}
