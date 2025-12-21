<?php

declare(strict_types=1);

namespace Flows\Contracts;

interface CLICommand
{
    /**
     * Show help text regarding CLI command
     * @return string
     */
    public function getHelp(): string;
    /**
     * Return command arguments and their description
     * @return array<string, string>
     */
    public function getArguments(): array;
    /**
     * Check if user provided command arguments are valid
     * @param array<string, mixed> $against
     * @return 
     */
    public function checkArguments(array $against): bool;
}
