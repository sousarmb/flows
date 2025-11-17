<?php

declare(strict_types=1);

namespace Flows\Traits;

use Flows\Attributes\Defer\DeferFromFlow;
use Flows\Attributes\Defer\DeferFromProcess;
use Flows\Attributes\Lazy;
use Flows\Attributes\Realtime;
use Flows\Attributes\Singleton;
use ReflectionClass;

trait Attributes
{

    public function deferFromFlow(string|object $nsClassOrObject): bool
    {
        $tmp = new ReflectionClass($nsClassOrObject);
        return (bool)$tmp->getAttributes(DeferFromFlow::class);
    }

    public function deferFromProcess(string|object $nsClassOrObject): bool
    {
        $tmp = new ReflectionClass($nsClassOrObject);
        return (bool)$tmp->getAttributes(DeferFromProcess::class);
    }

    public function isLazy(string|object $nsClassOrObject): bool
    {
        $tmp = new ReflectionClass($nsClassOrObject);
        $attrib = $tmp->getAttributes(Lazy::class);
        if ([] === $attrib) {
            return false;
        }

        return $attrib[0]->newInstance()->getIsLazy();
    }

    public function isRealtime(string|object $nsClassOrObject): bool
    {
        $tmp = new ReflectionClass($nsClassOrObject);
        return (bool)$tmp->getAttributes(Realtime::class);
    }

    public function isSingleton(string|object $nsClassOrObject): bool
    {
        $tmp = new ReflectionClass($nsClassOrObject);
        $attrib = $tmp->getAttributes(Singleton::class);
        if ([] === $attrib) {
            return false;
        }

        return $attrib[0]->newInstance()->getIsSingleton();
    }
}
