<?php

declare(strict_types=1);

namespace Flows\Processes\Internal;

use Collectibles\Collection;
use Collectibles\Contracts\IO as IOContract;
use Composer\InstalledVersions;
use Exception;
use Flows\ApplicationKernel;
use Flows\Attributes\Defer\DeferFromFlow;
use Flows\Attributes\Defer\DeferFromProcess;
use Flows\Attributes\Lazy;
use Flows\Attributes\Realtime;
use Flows\Attributes\Singleton;
use Flows\Config;
use Flows\Container\Container;
use Flows\Container\ServiceImplementation\Abstraction;
use Flows\Container\ServiceImplementation\Concrete;
use Flows\Contracts\EventHandler as EventHandlerContract;
use Flows\Contracts\Observer as ObserverContract;
use Flows\Contracts\Tasks\Task as TaskContract;
use Flows\Event\Kernel as EventKernel;
use Flows\Facades\Config as ConfigFacade;
use Flows\Facades\Events as EventFacade;
use Flows\Facades\Logger as LoggerFacade;
use Flows\Facades\Observers as ObserverFacade;
use Flows\Factory;
use Flows\Helpers\StdErrMonologHandler;
use Flows\Observer\Kernel as ObserverKernel;
use Flows\Processes\Process;
use Flows\Traits\RandomString;
use LogicException;
use Monolog\ErrorHandler;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use ReflectionClass;

class BootProcess extends Process
{
    public function __construct()
    {
        $this->tasks = [
            new class implements TaskContract {
                use RandomString;
                /**
                 * @param IOContract|Collection|null $io
                 * @return IOContract|null
                 */
                public function __invoke(?IOContract $io = null): ?IOContract
                {
                    /**
                     * 
                     * UUID for this flows execution
                     */
                    define('INSTANCE_UUID', $this->getHexadecimal(32));
                    return new Collection();
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
                    $ds = DIRECTORY_SEPARATOR;
                    $packageName = InstalledVersions::getRootPackage()['name'];
                    $vendorDir = InstalledVersions::getInstallPath($packageName);
                    // Where is the code?
                    $root_dir = dirname($vendorDir, 4) . $ds;
                    $application_dir = $root_dir . "App{$ds}";
                    $config_dir = $application_dir . "Config{$ds}";
                    $log_dir = $application_dir . "Logs{$ds}";
                    $files = [
                        'app.php',
                        'service-provider.php',
                        'event-handler.php',
                        'subject-observer.php',
                    ];

                    return $io->set(
                        (new Config())
                            ->set($root_dir, 'root.directory')
                            ->set($application_dir, 'app.directory')
                            ->set($config_dir, 'app.config.directory')
                            ->set($log_dir, 'app.log.directory')
                            ->set($files, 'app.config.files'),
                        Config::class
                    );
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
                    $config = $io->get(Config::class);
                    $logger = new Logger('debug');
                    if (ApplicationKernel::isOffloadedProcess()) {
                        $stream = $config->get('app.log.directory') . str_replace('\\', '-', OFFLOADED_PROCESS_NAME) . '.log';
                        // Notify main process as well
                        $logger->pushHandler(new StdErrMonologHandler(Level::Error));
                    } else {
                        $stream = $config->get('app.log.directory') . 'debug.log';
                    }

                    $handler = new StreamHandler($stream, Level::Debug);
                    $handler->setFormatter(new LineFormatter(includeStacktraces: true));
                    $logger->pushHandler($handler);
                    ErrorHandler::register($logger);
                    // Store to be inserted into the service container later
                    $io->set($logger, Logger::class);

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
                    $config = $io->get(Config::class);
                    if (!chdir($config->get('app.config.directory'))) {
                        throw new Exception('Could not change to application directory');
                    }

                    foreach ($config->get('app.config.files') as $file) {
                        if (!is_readable($file)) {
                            throw new Exception('Could not find or read configuration files');
                        }
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
                    $config = $io->get(Config::class);
                    $file = $config->get('app.config.files.0'); // app.php
                    $settings = require_once $config->get('app.config.directory') . $file;
                    if (!is_array($settings)) {
                        throw new Exception("Configuration settings in $file must be an array");
                    }

                    $collSettings = new Collection();
                    if ($settings) {
                        $collSettings->mergeInto($settings);
                    }

                    $config->set(
                        $collSettings,
                        'app.config.app'
                    );
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
                    $config = $io->get(Config::class);
                    $file = $config->get('app.config.files.1'); // service-provider.php
                    $serviceproviders = require_once $config->get('app.config.directory') . $file;
                    if (!is_array($serviceproviders)) {
                        throw new Exception("Configuration settings in $file must be an array");
                    }

                    $container = new Container();
                    // Register services into the container
                    foreach ($serviceproviders as $intOrAbstraction => $concreteOrProvider) {
                        $nsAbstractionOrConcrete = is_int($intOrAbstraction) ? $concreteOrProvider : $intOrAbstraction;
                        $abstractionOrConcrete = new ReflectionClass($nsAbstractionOrConcrete);

                        if ($abstractionOrConcrete->isInterface()) {
                            $provider = new ReflectionClass($concreteOrProvider);
                            $attribLazy = $provider->getAttributes(Lazy::class);
                            $attribSingleton = $provider->getAttributes(Singleton::class);
                            if ($provider->isInterface()) {
                                throw new LogicException("$concreteOrProvider: Interfaces are not allowed as service providers");
                            }

                            $container->register(
                                new Abstraction(
                                    $nsAbstractionOrConcrete,
                                    $concreteOrProvider,
                                    [] === $attribLazy ?: $attribLazy[0]->newInstance()->getIsLazy(),
                                    [] === $attribSingleton ?: $attribSingleton[0]->newInstance()->getIsSingleton()
                                )
                            );
                        } else {
                            $attribLazy = $abstractionOrConcrete->getAttributes(Lazy::class);
                            $attribSingleton = $abstractionOrConcrete->getAttributes(Singleton::class);
                            $container->register(
                                new Concrete(
                                    $concreteOrProvider,
                                    [] === $attribLazy ?: $attribLazy[0]->newInstance()->getIsLazy(),
                                    [] === $attribSingleton ?: $attribSingleton[0]->newInstance()->getIsSingleton()
                                )
                            );
                        }
                    }
                    // Set the container in the factory so the container boot process may access itself through
                    // the factory to access booted services
                    Factory::setContainer($container);
                    // Register configuration object, some service or provider might need it
                    $container->register(
                        new Concrete(Config::class, false, true, true),
                        $io->get(Config::class)
                    );
                    ConfigFacade::setContainer($container);
                    // Register application logger object, some service or provider might need it
                    $container->register(
                        new Concrete(Logger::class, false, true, true),
                        $io->get(Logger::class)
                    );
                    LoggerFacade::setContainer($container);
                    // Boot the container
                    $container->boot();

                    return $io->set($container, Container::class);
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
                    $config = $io->get(Config::class);
                    $file = $config->get('app.config.files.2'); // event-handler.php
                    $settings = require_once $config->get('app.config.directory') . $file;
                    if (!is_array($settings)) {
                        throw new Exception("Configuration settings in $file must be an array");
                    }

                    $eventKernel = Factory::getClassInstance(EventKernel::class);
                    foreach ($settings as $nsEvent => $nsHandler) {
                        $reflection = new ReflectionClass($nsHandler);
                        if (!$reflection->implementsInterface(EventHandlerContract::class)) {
                            throw new LogicException('Event handlers must implement ' . EventHandlerContract::class . 'interface');
                        }

                        $timing = $reflection->getAttributes(DeferFromFlow::class)
                            + $reflection->getAttributes(DeferFromProcess::class)
                            + $reflection->getAttributes(Realtime::class);
                        if (count($timing) > 1) {
                            $msg = sprintf(
                                'Invalid event timing in %s, must be one of: %s, %s, %s',
                                $nsHandler,
                                DeferFromFlow::class,
                                DeferFromProcess::class,
                                Realtime::class
                            );
                            throw new LogicException($msg);
                        }

                        $eventKernel->register(
                            $nsEvent,
                            $nsHandler,
                            [] === $timing ? new Realtime() : $timing[0]->newInstance()
                        );
                    }
                    $io->get(Container::class)
                        ->register(
                            new Concrete(EventKernel::class, false, true, true),
                            $eventKernel
                        );
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
                    $config = $io->get(Config::class);
                    $file = $config->get('app.config.files.3'); // subject-observer.php
                    $settings = require_once $config->get('app.config.directory') . $file;
                    if (!is_array($settings)) {
                        throw new Exception("Configuration settings in $file must be an array");
                    }

                    $observerKernel = Factory::getClassInstance(ObserverKernel::class);
                    foreach ($settings as $nsSubject => $nsObserver) {
                        $reflection = new ReflectionClass($nsObserver);
                        if (!$reflection->implementsInterface(ObserverContract::class)) {
                            throw new LogicException('Observer must implement ' . ObserverContract::class . 'interface');
                        }

                        $timing = $reflection->getAttributes(DeferFromFlow::class)
                            + $reflection->getAttributes(DeferFromProcess::class)
                            + $reflection->getAttributes(Realtime::class);
                        if (count($timing) > 1) {
                            $msg = sprintf(
                                'Invalid observer timing in %s, must be one of: %s, %s, %s',
                                $nsObserver,
                                DeferFromFlow::class,
                                DeferFromProcess::class,
                                Realtime::class
                            );
                            throw new LogicException($msg);
                        }

                        $observerKernel->register(
                            $nsSubject,
                            $nsObserver,
                            [] === $timing ? new Realtime() : $timing[0]->newInstance()
                        );
                    }
                    $io->get(Container::class)
                        ->register(
                            new Concrete(ObserverKernel::class, false, true, true),
                            $observerKernel
                        );
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
                    if (!chdir($io->get(Config::class)->get('app.directory'))) {
                        throw new Exception('Could not change to application directory');
                    }

                    $container = $io->get(Container::class);
                    EventFacade::setContainer($container);
                    ObserverFacade::setContainer($container);
                    return $io;
                }

                public function cleanUp(bool $forSerialization = false): void {}
            }
        ];
        parent::__construct();
    }
}
