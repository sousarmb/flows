<?php

declare(strict_types=1);

namespace Flows\Exceptions;

use RuntimeException;
use Throwable;

class HttpHandlerServerRunningException extends RuntimeException
{
    public function __construct(
        string $message = "HTTP server running",
        int $code = 0,
        Throwable|null $previous = null
    ) {
        return parent::__construct($message, $code, $previous);
    }
}
