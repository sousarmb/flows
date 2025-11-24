<?php

declare(strict_types=1);

namespace Tests\Unit\Processes;

use Collectibles\Collection;
use Collectibles\Contracts\IO;
use Flows\Contracts\Tasks\Task;
use Flows\Processes\Process;
use PHPUnit\Framework\TestCase;

class CreateAndWriteToFileProcess extends Process
{
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

                public function cleanUp(bool $forSerialization = false): void
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

                public function cleanUp(bool $forSerialization = false): void {}
            },
            new class implements Task {
                public function __invoke(?IO $io = null): ?IO
                {
                    fwrite($io->get('fileHandle'), 'more dummy content' . PHP_EOL);
                    return $io;
                }

                public function cleanUp(bool $forSerialization = false): void {}
            }
        ];
        parent::__construct();
    }
}

class ProcessTest extends TestCase
{
    /**
     * @covers \App\Processes\CreateAndWriteToFileProcess::__construct
     * @covers \Flows\Processes\Process::__construct
     */
    public function testConstructorSetsThreeTasks(): void
    {
        $process = new CreateAndWriteToFileProcess();

        $this->assertInstanceOf(Process::class, $process);

        $reflection = new \ReflectionClass($process);
        $property   = $reflection->getProperty('tasks');
        $tasks = $property->getValue($process);

        $this->assertCount(3, $tasks);
        $this->assertContainsOnlyInstancesOf(Task::class, $tasks);
    }

    /**
     * @covers \Flows\Processes\Process::run
     * @covers \Flows\Processes\Process::cleanUp
     */
    public function testProcessExecutesAllTasksCreatesFileAndCleansUp(): void
    {
        $process = new CreateAndWriteToFileProcess();
        $result = $process->run();

        $this->assertInstanceOf(Collection::class, $result);
        $this->assertInstanceOf(IO::class, $result);

        // The first task opens a file and stores the handle under 'fileHandle'
        $handle = $result->get('fileHandle');
        $this->assertIsResource($handle);
        $this->assertTrue(is_resource($handle));

        // Rewind and read content written by the two subsequent tasks
        rewind($handle);
        $content = stream_get_contents($handle);

        $this->assertStringContainsString('dummy content', $content);
        $this->assertStringContainsString('more dummy content', $content);

        $process->cleanUp();
        // After process() finishes, cleanUp() is automatically called → handle must be closed
        $this->assertFalse(is_resource($handle), 'File handle should be closed in cleanUp()');
    }

    /**
     * @covers \Flows\Processes\Process::run
     */
    public function testProcessWorksWithNullIo(): void
    {
        $process = new CreateAndWriteToFileProcess();

        $result = $process->run(null);

        $this->assertInstanceOf(Collection::class, $result);
        $this->assertInstanceOf(IO::class, $result);
    }
}
