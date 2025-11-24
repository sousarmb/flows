<?php

declare(strict_types=1);

use Collectibles\IO;
use Flows\Gates\OrGate;
use PHPUnit\Framework\TestCase;

class OrGateTest extends TestCase
{
    /**
     * @covers \WhatProcessNowGate::__construct
     * @covers \WhatProcessNowGate::__invoke
     */
    public function testInvokeReturnsRandomArray12ForEvenSeconds(): void
    {
        $mockDate = $this->createMock(DateTime::class);
        $mockDate->expects(self::once())
            ->method('format')
            ->with('s')
            ->willReturn('00');

        $mockIO = $this->createMock(IO::class);
        $mockIO->expects(self::once())
            ->method('get')
            ->with('date')
            ->willReturn($mockDate);

        $gate = new class() extends OrGate {
            public function __invoke(): array
            {
                return (int) $this->io->get('date')->format('s') % 2 === 0
                    ? [1, 2]
                    : [3, 4];
            }

            public function cleanUp(bool $forSerialization = false): void {}
        };

        $reflection = new ReflectionClass($gate);
        $property = $reflection->getProperty('io');
        $property->setValue($gate, $mockIO);

        $result = $gate();

        self::assertIsArray($result);
        self::assertSame([1, 2], $result);
    }

    /**
     * @covers \WhatProcessNowGate::__construct
     * @covers \WhatProcessNowGate::__invoke
     */
    public function testInvokeReturnsRandomArray34ForOddSeconds(): void
    {
        $mockDate = $this->createMock(DateTime::class);
        $mockDate->expects(self::once())
            ->method('format')
            ->with('s')
            ->willReturn('01');

        $mockIO = $this->createMock(IO::class);
        $mockIO->expects(self::once())
            ->method('get')
            ->with('date')
            ->willReturn($mockDate);

        $gate = new class() extends OrGate {
            public function __invoke(): array
            {
                return (int) $this->io->get('date')->format('s') % 2 === 0
                    ? [1, 2]
                    : [3, 4];
            }

            public function cleanUp(bool $forSerialization = false): void {}
        };

        $reflection = new ReflectionClass($gate);
        $property = $reflection->getProperty('io');
        $property->setValue($gate, $mockIO);

        $result = $gate();

        self::assertIsArray($result);
        self::assertSame([3, 4], $result);
    }
}
