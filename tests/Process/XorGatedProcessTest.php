<?php

declare(strict_types=1);

use Collectibles\Collection;
use Collectibles\Contracts\IO;
use Flows\Contracts\Gate;
use Flows\Contracts\Tasks\Task;
use Flows\Gates\XorGate;
use Flows\Processes\Process;
use PHPUnit\Framework\TestCase;

class XorGatedProcessTest extends TestCase
{
    private string $tempFile;

    protected function setUp(): void
    {
        $this->tempFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dummy';
    }

    /**
     * @covers \CreateAndWriteToFileProcess::__construct
     * @covers \CreateAndWriteToFileProcess::run
     */
    public function testProcessCreatesFileWritesContentAndReturnsOneOfTheRandomProcesses(): void
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
                    },
                    new class implements Task {
                        public function __invoke(?IO $io = null): ?IO
                        {
                            fwrite($io->get('fileHandle'), 'dummy content' . PHP_EOL);
                            return $io;
                        }

                        public function cleanUp(): void {}
                    },
                    new class extends XorGate {
                        public function __invoke(): string
                        {
                            return 'SomeRandomProcess';
                        }
                    }
                ];
                parent::__construct();
            }
        };

        $result = $process->process();

        self::assertFileExists($this->tempFile);
        $content = file_get_contents($this->tempFile);
        self::assertStringContainsString('dummy content', $content);

        self::assertInstanceOf(Gate::class, $result);
        self::assertEquals('SomeRandomProcess', $result());

        // Cleanup
        unlink($this->tempFile);
    }
}
