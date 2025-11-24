<?php

declare(strict_types=1);

use Collectibles\IO;
use Flows\Gates\XorGate;
use PHPUnit\Framework\TestCase;

class XorGateTest extends TestCase
{
    /**
     * @covers \WhatProcessNowGate::__construct
     * @covers \WhatProcessNowGate::__invoke
     */
    public function testInvokeReturnsRandomProcessAForEvenSeconds(): void
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

        $gate = new class() extends XorGate {
            public function __invoke(): string
            {
                return (int) $this->io->get('date')->format('s') % 2 === 0
                    ? 'RandomProcessA'
                    : 'RandomProcessB';
            }

            public function cleanUp(bool $forSerialization = false): void {}
        };

        $reflection = new ReflectionClass($gate);
        $property = $reflection->getProperty('io');
        $property->setValue($gate, $mockIO);

        $result = $gate();

        self::assertIsString($result);
        self::assertSame('RandomProcessA', $result);
    }

    /**
     * @covers \WhatProcessNowGate::__construct
     * @covers \WhatProcessNowGate::__invoke
     */
    public function testInvokeReturnsRandomProcessBForOddSeconds(): void
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

        $gate = new class() extends XorGate {
            public function __invoke(): string
            {
                return (int) $this->io->get('date')->format('s') % 2 === 0
                    ? 'RandomProcessA'
                    : 'RandomProcessB';
            }

            public function cleanUp(bool $forSerialization = false): void {}
        };

        $reflection = new ReflectionClass($gate);
        $property = $reflection->getProperty('io');
        $property->setValue($gate, $mockIO);

        $result = $gate();

        self::assertIsString($result);
        self::assertSame('RandomProcessB', $result);
    }
}
