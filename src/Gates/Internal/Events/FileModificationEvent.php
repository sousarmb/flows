<?php

declare(strict_types=1);

namespace Flows\Gates\Internal\Events;

use Flows\Contracts\Gates\Frequent as FrequentContract;
use Flows\Contracts\Gates\GateEvent as GateEventContract;
use LogicException;

/**
 * 
 * Event gate, waits n seconds till a file modification occurs
 */
final readonly class FileModificationEvent implements GateEventContract, FrequentContract
{
    private array $lastCheck;

    public function __construct(
        /**
         * @var string file to check for modifications
         */
        private string $file,
        /**
         * @var int frequency to check for modifications, in milliseconds
         */
        private float $frequency = 0
    ) {
        if (!is_file($this->file)) {
            throw new LogicException('File must exist for gate event');
        }

        clearstatcache(true, $this->file);
        $this->lastCheck = [filesize($file), filemtime($file)];
    }

    public function resolve($data = null): bool
    {
        clearstatcache(true, $this->file);
        $currentCheck = [filesize($this->file), filemtime($this->file)];
        return $currentCheck !== $this->lastCheck;
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
