<?php

declare(strict_types=1);

namespace Flows;

use Collectibles\Collection;
use Collectibles\IO;
use LogicException;

readonly class Config extends IO
{
    private bool $readonly;

    public function __construct(
        private Collection $settings = new Collection()
    ) {}

    public function get(string $name): mixed
    {
        return $this->settings->get($name);
    }

    public function has(string $key): bool
    {
        return $this->settings->has($key);
    }

    public function set(mixed $value, string $name): self
    {
        if (isset($this->readonly)) {
            throw new LogicException('Configuration in read-only mode');
        }

        $this->settings->set($value, $name);
        return $this;
    }

    public function getReadOnly(): bool
    {
        return $this->readonly ?: false;
    }

    public function setReadOnly(): bool
    {
        return $this->readonly = true;
    }

    public function dump(): array
    {
        return $this->settings->toArray();
    }
}
