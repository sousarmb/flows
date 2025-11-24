<?php

declare(strict_types=1);

use Collectibles\Collection;
use Collectibles\Contracts\IO;
use Flows\Contracts\Gate;
use Flows\Contracts\Tasks\Task;
use Flows\Gates\OrGate;
use Flows\Processes\Process;
use PHPUnit\Framework\TestCase;

class InterruptedOrGatedProcessTest extends TestCase
{
    private string $tempFile;

    protected function setUp(): void
    {
        $this->tempFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dummy';
    }

    protected function tearDown(): void
    {
        unlink($this->tempFile);
        parent::tearDown();
    }

    /**
     * @covers \CreateAndWriteToFileProcess::__construct
     * @covers \CreateAndWriteToFileProcess::run
     */
    public function testProcessCreatesFileWritesContentAndReturnsArrayOfProcessNames(): void
    {
        $process = new class extends Process {
            public function __construct()
            {
                $this->tasks = [
                    new class implements Task {
                        private Collection $coll;
                        public function __invoke(?IO $io = null): ?IO
                        {
                            $this->coll = new Collection();
                            $this->coll->set(
                                fopen(
                                    sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dummy',
                                    'w+'
                                ),
                                'fileHandle'
                            );

                            return $this->coll;
                        }

                        public function cleanUp(): void
                        {
                            fclose($this->coll->get('fileHandle'));
                        }

                        public function __sleep(): array
                        {
                            return [];
                        }

                        public function __wakeup(): void {}
                    },
                    new class extends OrGate {
                        public function __invoke(): array
                        {
                            return ['SomeRandomProcessA', 'SomeRandomProcessB'];
                        }
                    },
                    new class implements Task {
                        public function __invoke(?IO $io = null): ?IO
                        {
                            fwrite($io->get('fileHandle'), 'dummy content' . PHP_EOL);
                            return $io;
                        }

                        public function __sleep(): array
                        {
                            return [];
                        }

                        public function __wakeup(): void {}

                        public function cleanUp(): void {}
                    },
                ];
                parent::__construct();
            }
        };

        $result = $process->run();

        self::assertFileExists($this->tempFile);
        self::assertInstanceOf(Gate::class, $result);
        self::assertEquals(['SomeRandomProcessA', 'SomeRandomProcessB'], $result());
    }
}
