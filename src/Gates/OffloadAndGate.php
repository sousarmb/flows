<?php

declare(strict_types=1);

namespace Flows\Gates;

/**
 * 
 * Same as parallel gate, all outgoing paths are taken simultaneously but in separate processes (#PID), join and resume main process
 */
abstract class OffloadAndGate extends AndGate
{
}
