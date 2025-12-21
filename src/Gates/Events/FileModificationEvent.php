<?php

declare(strict_types=1);

namespace Flows\Gates\Events;

use Flows\Contracts\Gates\Frequent as FrequentContract;
use Flows\Contracts\Gates\GateEvent as GateEventContract;
use LogicException;

/**
 * 
 * Event gate event, check every N seconds if a given file is modified (file size and modification time)
 */
final readonly class FileModificationEvent implements FrequentContract, GateEventContract 
{
    private array $lastCheck;

    public function __construct(
        /**
         * @var string file to check for modifications
         */
        private string $file,
        /**
         * @var float frequency to check for modifications, in seconds
         */
        private float $frequency = 1
    ) {
        if (!is_file($this->file)) {
            throw new LogicException('File must exist to gate event');
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
