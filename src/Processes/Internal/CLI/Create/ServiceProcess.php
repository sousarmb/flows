<?php

declare(strict_types=1);

namespace Flows\Processes\Internal\CLI\Create;

use Collectibles\Contracts\IO as IOContract;
use Flows\Contracts\Tasks\Task as TaskContract;
use Flows\Processes\CLICommand;
use Flows\Processes\Internal\CLI\CheckAppScaffoldExistsTask;
use Flows\Processes\Internal\CLI\CheckForCommandFlagTask;
use Flows\Processes\Internal\IO\CLICollection;
use Flows\Processes\Internal\IO\CommandOutput;
use Flows\Traits\ClassChecker;
use InvalidArgumentException;
use LogicException;
use RuntimeException;

class ServiceProcess extends CLICommand
{
    protected string $help = 'Create and register a named service and/or service provider in App/Services';
    protected array $arguments = [
        'name' => '=[name] Service class name',
        'lazy' => '=[no|yes] Lazy load service',
        'singleton' => '=[no|yes] Container storage',
        'implementation' => '=[abstract|concrete] Abstract creates service provider, concrete does not',
        'contract' => '=[name] Service returned by the provider must implement this contract, use only on abstract implementation'
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
                            throw new InvalidArgumentException('Invalid service name');
                        } elseif ($this->classFileExists($name, 'service', $io->getScaffoldDestinationDirectory())) {
                            throw new InvalidArgumentException('Service class file exists');
                        } elseif ($this->classFileExists($name, 'serviceprovider', $io->getScaffoldDestinationDirectory())) {
                            throw new InvalidArgumentException('Service provider class file exists');
                        }

                        return $io;
                    }
                    // Query the user
                    $tryAgain = true;
                    do {
                        $name = readline('Service class name? ');
                        if (!$this->classNameIsValid($name)) {
                            echo PHP_EOL . 'Invalid service name' . PHP_EOL;
                        } elseif ($this->classFileExists($name, 'service', $io->getScaffoldDestinationDirectory())) {
                            echo PHP_EOL . 'Service class file exists' . PHP_EOL;
                        } elseif ($this->classFileExists($name, 'serviceprovider', $io->getScaffoldDestinationDirectory())) {
                            echo PHP_EOL . 'Service provider class file exists' . PHP_EOL;
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
                    $lazy = $io->get('argv.lazy');
                    if ($lazy) {
                        if (!in_array($lazy, ['no', 'yes'])) {
                            throw new InvalidArgumentException('Invalid lazy string');
                        }

                        return $io;
                    }
                    // Query the user
                    $tryAgain = true;
                    do {
                        $lazy = readline('Lazy load service [no|yes]? ');
                        if (in_array($lazy, ['no', 'yes'])) {
                            $tryAgain = false;
                        } else {
                            echo PHP_EOL . 'Invalid lazy string' . PHP_EOL;
                        }
                    } while ($tryAgain);
                    return $io->set($lazy, 'argv.lazy');
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
                    $singleton = $io->get('argv.singleton');
                    if ($singleton) {
                        if (!in_array($singleton, ['no', 'yes'])) {
                            throw new InvalidArgumentException('Invalid singleton string');
                        }

                        return $io;
                    }
                    // Query the user
                    $tryAgain = true;
                    do {
                        $singleton = readline('Singleton service instance [no|yes]? ');
                        if (in_array($singleton, ['no', 'yes'])) {
                            $tryAgain = false;
                        } else {
                            echo PHP_EOL . 'Invalid singleton string' . PHP_EOL;
                        }
                    } while ($tryAgain);
                    return $io->set($singleton, 'argv.singleton');
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
                    if ($implementation = $io->get('argv.implementation')) {
                        if (!in_array($implementation, ['abstract', 'concrete'])) {
                            throw new InvalidArgumentException('Implementation is: abstract|concrete');
                        }

                        return $io;
                    }
                    // Query the user
                    $tryAgain = true;
                    do {
                        $implementation = readline('Implementation is abstract|concrete? ');
                        if (!in_array($implementation, ['abstract', 'concrete'])) {
                            echo PHP_EOL . 'Invalid implementation string' . PHP_EOL;
                        } else {
                            $io->set($implementation, 'argv.implementation');
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
                    if ('concrete' === $io->get('argv.implementation')) {
                        if ($io->get('argv.contract')) {
                            throw new LogicException('Concrete implementation does not use service provider');
                        }

                        return $io;
                    }

                    $contract = $io->get('argv.contract');
                    if ($contract) {
                        if (!$this->classNameIsValid($contract)) {
                            throw new InvalidArgumentException('Invalid string for contract class name');
                        } elseif ($this->classFileExists($contract, 'contract', $io->getScaffoldDestinationDirectory())) {
                            throw new InvalidArgumentException('Contract class file exists');
                        }

                        return $io;
                    }
                    // Query the user
                    $tryAgain = true;
                    do {
                        $contract = readline('Contract class name? ');
                        if (!$this->classNameIsValid($contract)) {
                            echo PHP_EOL . 'Invalid string for contract class name' . PHP_EOL;
                        } elseif ($this->classFileExists($contract, 'contract', $io->getScaffoldDestinationDirectory())) {
                            echo PHP_EOL . 'Contract class file exists' . PHP_EOL;
                        } else {
                            $tryAgain = false;
                        }
                    } while ($tryAgain);
                    return $io->set($contract, 'argv.contract');
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
                    $fileContents = file_get_contents($io->getScaffoldTemplatesDirectory() . "{$ds}service.{$io->get('argv.implementation')}.php.template");
                    if ('concrete' === $io->get('argv.implementation')) {
                        $fileContents = str_replace(
                            [
                                '<!--is-lazy-->',
                                '<!--is-singleton-->'
                            ],
                            [
                                ('yes' === $io->get('argv.lazy') ? 'true' : 'false'),
                                ('yes' === $io->get('argv.singleton') ? 'true' : 'false')
                            ],
                            $fileContents
                        );
                    } else {
                        $fileContents = str_replace(
                            '<!--implements-contract-->',
                            $io->get('argv.contract'),
                            $fileContents
                        );
                    }

                    $fileContents = str_replace(
                        '<!--service-name-->',
                        $io->get('argv.name'),
                        $fileContents
                    );
                    $serviceFile = sprintf(
                        "%sServices{$ds}%sService.php",
                        $io->getScaffoldDestinationDirectory(),
                        $io->get('argv.name')
                    );
                    if (false === file_put_contents($serviceFile, $fileContents)) {
                        throw new RuntimeException("Could not write file {$serviceFile}");
                    }

                    return $io->set($serviceFile, 'newservicefile');
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
                    if ('concrete' === $io->get('argv.implementation')) {
                        return $io;
                    }

                    $ds = DIRECTORY_SEPARATOR;
                    $fileContents = file_get_contents($io->getScaffoldTemplatesDirectory() . "{$ds}serviceprovider.php.template");
                    $fileContents = str_replace(
                        [
                            '<!--is-lazy-->',
                            '<!--is-singleton-->',
                            '<!--service-provider-name-->',
                            '<!--return-contract-name-->'
                        ],
                        [
                            ('yes' === $io->get('argv.lazy') ? 'true' : 'false'),
                            ('yes' === $io->get('argv.singleton') ? 'true' : 'false'),
                            $io->get('argv.name'),
                            $io->get('argv.contract')
                        ],
                        $fileContents
                    );
                    $serviceProviderFile = sprintf(
                        "%sServices{$ds}Providers{$ds}%sServiceProvider.php",
                        $io->getScaffoldDestinationDirectory(),
                        $io->get('argv.name')
                    );
                    if (false === file_put_contents($serviceProviderFile, $fileContents)) {
                        throw new RuntimeException("Could not write file {$serviceProviderFile}");
                    }

                    return $io->set($serviceProviderFile, 'newserviceproviderfile');
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
                    if ('concrete' === $io->get('argv.implementation')) {
                        return $io;
                    }

                    $ds = DIRECTORY_SEPARATOR;
                    $fileContents = file_get_contents($io->getScaffoldTemplatesDirectory() . "{$ds}contract.php.template");
                    $fileContents = str_replace(
                        '<!--contract-name-->',
                        $io->get('argv.contract'),
                        $fileContents
                    );
                    $contractFile = sprintf(
                        "%sServices{$ds}Contracts{$ds}%sContract.php",
                        $io->getScaffoldDestinationDirectory(),
                        $io->get('argv.contract')
                    );
                    if (false === file_put_contents($contractFile, $fileContents)) {
                        throw new RuntimeException("Could not write file {$contractFile}");
                    }

                    return $io->set($contractFile, 'newcontractfile');
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
                    $serviceProviderMapFile = $io->getScaffoldDestinationDirectory() . "Config{$ds}service-provider.php";
                    $map = require_once $serviceProviderMapFile;
                    // Add to map
                    if ('concrete' === $io->get('argv.implementation')) {
                        $nsService = sprintf('App\\Services\\%sService::class', $io->get('argv.name'));
                        $map[] = $nsService;
                    } else {
                        $nsContract = sprintf('App\\Services\\Contracts\\%sContract::class', $io->get('argv.contract'));
                        $nsServiceProvider = sprintf('App\\Services\\Providers\\%sServiceProvider::class', $io->get('argv.name'));
                        $map[$nsContract] = $nsServiceProvider;
                    }
                    // Render map
                    $templateMapFile = $io->getScaffoldSourceDirectory() . "Config{$ds}service-provider.php";
                    $template = file_get_contents($templateMapFile);
                    $textMap = '';
                    foreach ($map as $nsContractOrIndex => $nsServiceOrProvider) {
                        $needsSuffix = false;
                        if (false === strrpos($nsServiceOrProvider, '::class')) {
                            $nsServiceOrProvider .= '::class';
                            $needsSuffix = true;
                        }
                        if (is_int($nsContractOrIndex)) {
                            $textMap .= "\t{$nsServiceOrProvider}," . PHP_EOL;
                        } else {
                            if ($needsSuffix) {
                                $nsContractOrIndex .= '::class';
                            }

                            $textMap .= "\t{$nsContractOrIndex} => {$nsServiceOrProvider}," . PHP_EOL;
                        }
                    }
                    $find = "return [];";
                    $replace = sprintf('return [%s%s];', PHP_EOL, $textMap);
                    $contents = str_replace($find, $replace, $template);
                    if (false === file_put_contents($serviceProviderMapFile, $contents)) {
                        throw new RuntimeException('Could not register new service to configuration file');
                    }

                    $filesWritten = [
                        $serviceProviderMapFile,
                        $io->get('newservicefile'),
                    ];
                    if ('abstract' === $io->get('argv.implementation')) {
                        $filesWritten[] = $io->get('newcontractfile');
                        $filesWritten[] = $io->get('newserviceproviderfile');
                    }

                    return new CommandOutput(
                        'Service created successfully [' . implode(', ', $filesWritten) . ']',
                        true
                    );
                }

                public function cleanUp(bool $forSerialization = false): void {}
            }
        ];
        parent::__construct();
    }
}
