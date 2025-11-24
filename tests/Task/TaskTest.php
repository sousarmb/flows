<?php

declare(strict_types=1);

namespace Tests\Task;

use Collectibles\Collection;
use Collectibles\Contracts\IO;
use Flows\Contracts\Tasks\Task;
use PHPUnit\Framework\TestCase;

class DummyTask implements Task
{
    private Collection $coll;

    public function __invoke(?IO $io = null): ?IO
    {
        $this->coll = new Collection();
        $this->coll->set(1, 'item');
        return $io;
    }

    public function cleanUp(bool $forSerialization = false): void
    {
        if (isset($this->coll)) {
            $this->coll->delete('item');
        }
    }
}

/**
 * @covers \App\Processes\Tasks\DummyTask
 */
class DummyTaskTest extends TestCase
{
    private $task;

    protected function setUp(): void
    {
        parent::setUp();
        $this->task = new DummyTask();
    }

    /**
     * @covers \App\Processes\Tasks\DummyTask::__invoke
     */
    public function test_implements_task_interface_and_is_callable(): void
    {
        $this->assertInstanceOf(Task::class, $this->task);
        $this->assertIsCallable($this->task);
    }

    /**
     * @covers \App\Processes\Tasks\DummyTask::__invoke
     */
    public function test_invoke_creates_new_collection_and_adds_item(): void
    {
        $io = $this->createMock(IO::class);
        $this->task->__invoke($io);

        $reflection = new \ReflectionClass($this->task);
        $prop = $reflection->getProperty('coll');

        $collection = $prop->getValue($this->task);

        $this->assertInstanceOf(Collection::class, $collection);
        $this->assertTrue($collection->has('item'));
        $this->assertEquals(1, $collection->get('item'));
    }

    /**
     * @covers \App\Processes\Tasks\DummyTask::cleanUp
     */
    public function test_cleanUp_removes_all_items_from_collection(): void
    {
        $this->task->__invoke();
        $this->task->cleanUp();

        $reflection = new \ReflectionClass($this->task);
        $prop = $reflection->getProperty('coll');

        $collection = $prop->getValue($this->task);

        $this->assertInstanceOf(Collection::class, $collection);
        $this->assertEquals(0, $collection->getSize());
    }

    /**
     * @covers \App\Processes\Tasks\DummyTask::cleanUp
     */
    public function test_cleanUp_is_safe_when_collection_was_not_created(): void
    {
        $this->task->cleanUp();
        $this->assertTrue(true);
    }

    /**
     * @covers \App\Processes\Tasks\DummyTask::__invoke
     */
    public function test_invoke_returns_passed_io_unchanged(): void
    {
        $io = $this->createMock(IO::class);
        $result = $this->task->__invoke($io);
        $this->assertSame($io, $result);
    }

    /**
     * @covers \App\Processes\Tasks\DummyTask::__invoke
     */
    public function test_invoke_works_with_null_io(): void
    {
        $this->assertNull($this->task->__invoke());
    }
}
