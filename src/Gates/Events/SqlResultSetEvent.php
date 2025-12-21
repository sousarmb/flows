<?php

declare(strict_types=1);

namespace Flows\Gates\Events;

use Flows\Contracts\Gates\Frequent as FrequentContract;
use Flows\Contracts\Gates\GateEvent as GateEventContract;
use PDOStatement;
use RuntimeException;

/**
 * 
 * Event gate event, run prepared statement every N seconds, resolves on result set size of 1 or more (column count)
 */
final readonly class SqlResultSetEvent implements FrequentContract, GateEventContract 
{
    public function __construct(
        /**
         * @var string SQL prepared statement, with positional values
         */
        private PDOStatement $stmt,
        /**
         * @var array|null Array of positional values to use with prepared statement
         */
        private ?array $stmtValues = null,
        /**
         * @var float frequency to run statement, in seconds
         */
        private float $frequency = 1
    ) {}

    public function resolve($data = null): bool
    {
        if (!$this->stmt->execute($this->stmtValues)) {
            throw new RuntimeException('Could not execute prepared statement');
        }

        return (bool)$this->stmt->fetchColumn();
    }

    public function getFrequency(): float
    {
        return $this->frequency;
    }

    public function setFrequency(float $milliseconds): void
    {
        $this->frequency = $milliseconds;
    }
}
