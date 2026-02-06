<?php

declare(strict_types=1);

namespace Flows\Processes\Internal;

use Collectibles\Collection;
use Collectibles\Contracts\IO as IOContract;
use Composer\InstalledVersions;
use Flows\Contracts\Tasks\Task as TaskContract;
use Flows\Exceptions\CouldNotStartHttpServerException;
use Flows\Exceptions\HttpHandlerServerRunningException;
use Flows\Facades\Config;
use Flows\Facades\Logger;
use Flows\Processes\Process;
use Flows\Traits\Echos;
use Flows\Traits\RandomString;
use RuntimeException;

class StartHttpServerProcess extends Process
{
    public function __construct()
    {
        $this->tasks = [
            new class implements TaskContract {
                use Echos;
                /**
                 * @param IOContract|Collection|null $io
                 * @return IOContract|null
                 */
                public function __invoke(?IOContract $io = null): ?IOContract
                {
                    if ($this->pingHandlerServer()) {
                        throw new HttpHandlerServerRunningException();
                    }

                    $ds = DIRECTORY_SEPARATOR;
                    $packageDir = InstalledVersions::getInstallPath(FLOWS_PACKAGE_NAME);
                    $binDir = dirname($packageDir, 4) . "{$ds}bin";
                    if (!chdir($binDir)) {
                        throw new RuntimeException("Could not change to HTTP server runtime directory: {$binDir}");
                    }
                    if (
                        !is_readable('http-server')
                        || !is_executable('http-server')
                    ) {
                        throw new RuntimeException('HTTP server runtime not found, not readable or not executable');
                    }

                    $cmdSocketPath = Config::getApplicationSettings()->get('http.server.command_socket_path');
                    @unlink($cmdSocketPath); // fresh start
                    return $io;
                }

                public function cleanUp(bool $forSerialization = false): void
                {
                    if (
                        !$forSerialization
                        && !chdir(STARTER_DIRECTORY)
                    ) {
                        throw new RuntimeException('Could not change directory to starter directory');
                    }
                }
            },
            new class implements TaskContract {
                use Echos;
                use RandomString;
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
                    $address = $settings->get('http.server.address');
                    $cmdSocketPath = $settings->get('http.server.command_socket_path');
                    $externalProcessReadTimeout = $settings->get('http.server.timeout_read_external_process');
                    $uid = $this->getHexadecimal(8);
                    $httpServer = proc_open(
                        [
                            './http-server',
                            '--address',
                            $address,
                            '--command-socket',
                            $cmdSocketPath,
                            '--server-uid',
                            $uid,
                            '--timeout-read-external-process',
                            $externalProcessReadTimeout
                        ],
                        $descriptorSpec,
                        $pipes,
                        getcwd()
                    );
                    if (false === $httpServer) {
                        @unlink($cmdSocketPath);
                        throw new CouldNotStartHttpServerException();
                    }

                    Logger::info("Started HTTP handler server: address {$address}, command socket {$cmdSocketPath}, unique identifier {$uid}");
                    return $io;
                }

                public function cleanUp(bool $forSerialization = false): void {}
            },
        ];

        parent::__construct();
    }
}
