<?php

declare(strict_types=1);

namespace Flows\Processes;

use Flows\Contracts\CLICommand as CLICommandContract;

abstract class CLICommand extends Process implements CLICommandContract
{
    protected string $help = 'N/A';
    protected array $arguments = [];

    public function checkArguments(array $against): bool
    {
        return [] === array_diff_key($against, $this->getArguments());
    }

    public function getHelp(): string
    {
        return $this->help;
    }

    public function getArguments(): array
    {
        return $this->arguments;
    }
}
