<?php

declare(strict_types=1);

namespace Flows\Contracts\Gates;

interface HttpEvent
{

    /**
     * Message that represents success to resume flow. Client must not retry to access this resource.
     * 
     * @param int $code Custom code
     * @param string $status Custom status
     * @param mixed $message Custom message
     * @throws RuntimeException If unable to send message to HTTP server
     * @return bool Always TRUE, signals the reactor to stop waiting on events
     */
    public function accepted(
        int $code,
        string $status,
        mixed $message
    ): bool;

    /**
     * Accept the handler server relayed request as client 
     * 
     * @throws RuntimeException If unable to get client from server
     * @return resource The client socket
     */
    public function acceptClient(): mixed;

    /**
     * Message that represents failure to resume flow. Client must try again with another request.
     * 
     * @param int $code Custom code
     * @param string $status Custom status
     * @param mixed $message Custom message
     * @throws RuntimeException If unable to send message to HTTP server
     * @return bool Always FALSE, signals the reactor to keep waiting on events
     */
    public function tryAgain(
        int $code,
        string $status,
        mixed $message
    ): bool;
}
