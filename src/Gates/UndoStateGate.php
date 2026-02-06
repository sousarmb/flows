<?php

declare(strict_types=1);

namespace Flows\Gates;

/**
 * 
 * Allows the process state to change without leaving the process.
 * Process must have saved states (using save signs) before undo state change can occur.
 */
abstract class UndoStateGate extends Gate
{
    /**
     * @return int 0 don't perform undo, just follow through with process, 1+ pop n states from stack
     */
    abstract public function __invoke(): int;
}
