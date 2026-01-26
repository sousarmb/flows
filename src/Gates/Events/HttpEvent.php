<?php

declare(strict_types=1);

namespace Flows\Gates\Events;

use Flows\Contracts\Gates\GateEvent as GateEventContract;
use Flows\Contracts\Gates\Stream as StreamContract;
use Flows\Facades\Config;
use Flows\Facades\Logger;
use Flows\Helpers\ResponseMessageToHttpRequest;
use Flows\Traits\RandomString;
use RuntimeException;

/**
 * 
 * Event gate event, process HTTP requests received by helper HTTP server
 */
abstract readonly class HttpEvent implements GateEventContract, StreamContract
{
    use RandomString;

    const TIMEOUT = 600; // 5 minutes

    /**
     * @var resource
     */
    private mixed $streamFilter;

    /**
     * @var string $fromServerPipe File path
     */
    private string $fromServerPipe;

    /**
     * @var string $toServerPipe File path
     */
    private string $toServerPipe;

    /**
     * @var string $path
     */
    protected string $path;

    /**
     * @var array $allowedMethods
     */
    protected array $allowedMethods;

    /**
     * @var resource $fromServerStream Receive messages from HTTP server here
     */
    private mixed $fromServerStream;

    /**
     * @var resource $toServerStream Send messages to HTTP server here
     */
    private mixed $toServerStream;

    /**
     * @var int $timeout In seconds, how long the HTTP server keeps this resource, default is TIMEOUT seconds
     */
    protected int $timeout;

    /**
     * 
     * Register gate event with HTTP server
     * 
     * @throws RuntimeException If unable to open command pipe file or register paths with HTTP server
     */
    private function registerPathWithHttpServer(): void
    {
        $commandPipeFile = Config::getApplicationSettings()->get('http.server.command_pipe_path');
        $commandPipe = fopen($commandPipeFile, 'w');
        if (false === $commandPipe) {
            throw new RuntimeException("Could not open command pipe to HTTP server: {$commandPipeFile}");
        }

        // stream_set_blocking($commandPipe, false);
        $command = json_encode([
            'command' => 'REGISTER',
            'path' => $this->path,
            // The HTTP server writes to this stream
            'pipe_from' => $this->fromServerPipe,
            /**
             * This external program writes to this stream; 
             * resolve() must write to this stream for a response to be sent from the HTTP server
             */
            'pipe_to' => $this->toServerPipe,
            'external_process_id' => INSTANCE_UID,
            'allowed_methods' => $this->allowedMethods,
            'timeout' => isset($this->timeout) ? $this->timeout : self::TIMEOUT
        ]);
        $write = fwrite($commandPipe, "{$command}\n");
        fclose($commandPipe);
        if (false === $write) {
            throw new RuntimeException("Could not register path: {$command}");
        }

        Logger::info("Registered path: {$this->path}");
    }

    /**
     * 
     * Create streams to communicate with HTTP server: one to send messages, one to receive
     * 
     * @throws RuntimeException If unable to create/open communication streams with the HTTP server
     */
    private function createStreams(): void
    {
        $temp = sys_get_temp_dir() . DIRECTORY_SEPARATOR;
        $this->fromServerPipe = $temp . $this->getHexadecimal(8, 'flows-from-server-pipe-');
        if (!posix_mkfifo($this->fromServerPipe, 0664)) {
            throw new RuntimeException("Could not create pipe file: {$this->fromServerPipe}");
        }

        Logger::info("Created pipe: {$this->fromServerPipe}");
        if (false === $this->fromServerStream = fopen($this->fromServerPipe, 'r+')) {
            @unlink($this->fromServerPipe);
            throw new RuntimeException("Could not open pipe file: {$this->fromServerPipe}");
        }

        $this->toServerPipe = $temp . $this->getHexadecimal(8, 'flows-to-server-pipe-');
        if (!posix_mkfifo($this->toServerPipe, 0664)) {
            throw new RuntimeException("Could not create pipe file: {$this->toServerPipe}");
        }

        Logger::info("Created pipe: {$this->toServerPipe}");
        if (false === $this->toServerStream = fopen($this->toServerPipe, 'w+')) {
            @unlink($this->toServerPipe);
            throw new RuntimeException("Could not open pipe file: {$this->toServerPipe}");
        }

        stream_set_blocking($this->fromServerStream, false);
        stream_set_blocking($this->toServerStream, false);
    }

    /**
     * 
     * Close the streams used for communication between the HTTP server and this process
     */
    public function closeStream(): void
    {
        if (isset($this->fromServerStream) && is_resource($this->fromServerStream)) {
            fclose($this->fromServerStream);
            @unlink($this->fromServerPipe);
            Logger::info("Closed pipe: {$this->fromServerPipe}");
        }
        if (isset($this->toServerStream) && is_resource($this->toServerStream)) {
            fclose($this->toServerStream);
            @unlink($this->toServerPipe);
            Logger::info("Closed pipe: {$this->toServerPipe}");
        }
    }

    /**
     * 
     * Get the stream the HTTP server writes to when a request is received.
     * If communication pipes don't exist create them.
     * 
     * @return resource 
     */
    public function getStream(): mixed
    {
        if (
            !isset($this->fromServerStream)
            && !isset($this->toServerStream)
        ) {
            $this->createStreams();
            $this->registerPathWithHttpServer();
        }
        // This stream will be passed to resolve() when a request that matches 
        // criteria (path + method(s)) arrives on the HTTP server
        return $this->fromServerStream;
    }

    public function __destruct()
    {
        $this->closeStream();
    }

    /**
     * Message that represents success to resume flow. Client must not retry to access this resource.
     * 
     * @param int $code Custom code
     * @param string $status Custom status
     * @param string $message Custom message
     * @throws RuntimeException If unable to send message to HTTP server
     */
    public function accepted(
        int $code,
        string $status,
        mixed $message
    ): void {
        $response = new ResponseMessageToHttpRequest(true, $code, $status, $message);
        if (false === fwrite($this->toServerStream, json_encode($response) . "\n")) {
            throw new RuntimeException("Could not write to HTTP server stream");
        }
    }

    /**
     * Message that represents failure to resume flow. Client must try again with another request.
     * 
     * @param int $code Custom code
     * @param string $status Custom status
     * @param string $message Custom message
     * @throws RuntimeException If unable to send message to HTTP server
     */
    public function tryAgain(
        int $code,
        string $status,
        string $message
    ): void {
        $response = new ResponseMessageToHttpRequest(false, $code, $status, $message);
        if (false === fwrite($this->toServerStream, json_encode($response) . "\n")) {
            throw new RuntimeException("Could not write to HTTP server stream");
        }
    }
}
