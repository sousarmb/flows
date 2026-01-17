<?php

declare(strict_types=1);

namespace Flows\Contracts\Gates;

interface EventGate
{
    /**
     * Get default gate timeout
     */
    public function getTimeout(): int;

    /**
     * Check if HTTP events are registered in the gate
     */
    public function hasFrequentEvents(): bool;

    /**
     * Check if HTTP events are registered in the gate
     */
    public function hasHttpEvents(): bool;

    /**
     * Check if HTTP events are registered in the gate
     */
    public function hasStreamEvents(): bool;

    /**
     * Register event in the event gate
     */
    public function registerEvents(): void;

    /**
     * Start the wait on events process
     */
    public function waitForEvent(): void;
}
