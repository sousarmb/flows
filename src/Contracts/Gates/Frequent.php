<?php

declare(strict_types=1);

namespace Flows\Contracts\Gates;

interface Frequent
{
    /**
     * 
     * Get polling frequency
     */
    public function getFrequency(): float;

    /**
     * 
     * Set polling frequency
     */
    public function setFrequency(float $milliseconds): void;
}
