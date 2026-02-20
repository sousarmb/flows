<?php

declare(strict_types=1);

namespace Flows\Helpers;

enum Behaviour: string
{
    case Continue = 'continue';
    case Exit = 'exit';
    case Resolve = 'resolve';
    case Serialize = 'serialize';
}
