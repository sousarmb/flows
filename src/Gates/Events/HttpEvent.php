<?php

declare(strict_types=1);

namespace Flows\Gates\Events;

use Flows\Contracts\Gates\GateEvent as GateEventContract;
use Flows\Contracts\Gates\Stream as StreamContract;
use Flows\Facades\Config;
use Flows\Facades\Logger;
use Flows\Traits\RandomString;
use LogicException;
use RuntimeException;

/**
 * 
 * Event gate event, process HTTP requests received by helper HTTP server
 */
abstract readonly class HttpEvent implements GateEventContract, StreamContract
{
    use RandomString;

    /**
     * @var string $fromServerPipe
     */
    private string $fromServerPipe;

    /**
     * @var string $toServerPipe
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
    protected mixed $fromServerStream;

    /**
     * @var resource $toServerStream Send messages to HTTP server here
     */
    protected mixed $toServerStream;

    /**
     * 
     * Register gate event with HTTP server
     */
    private function registerPathWithHttpServer(): void
    {
        $commandPipe = fopen(Config::getApplicationSettings()->get('http.server.command_pipe_path'), 'w');
        stream_set_blocking($commandPipe, false);
        $command = json_encode([
            'command' => 'REGISTER',
            'path' => $this->path,
            'pipe_to' => $this->toServerStream, // resolve() must write to this stream for a response to be sent from the server
            'pipe_from' => $this->fromServerStream, // the reactor reads this stream
            'external_process_id' => INSTANCE_UUID,
            'allowed_methods' => $this->allowedMethods,
        ]);
        if (false === fwrite($commandPipe, $command . PHP_EOL)) {
            throw new RuntimeException("Could not register command: {$command}");
        }

        fclose($commandPipe);
        Logger::info("Registered path: {$this->path}");
    }

    /**
     * 
     * Create streams to communicate with HTTP server: one to send messages, one to receive
     * 
     * @throws LogicException If streams already set
     */
    private function createStreams(): void
    {
        $temp = sys_get_temp_dir();
        $this->fromServerPipe = $temp . $this->getHexadecimal(8, 'flows-');
        $this->toServerPipe = $temp . $this->getHexadecimal(8, 'flows-');
        if (false === posix_mkfifo($this->fromServerPipe, 664)) {
            throw new RuntimeException("Could not create pipe file: {$this->fromServerPipe}");
        }
        if (false === $this->fromServerStream = fopen($this->fromServerPipe, 'r+')) {
            throw new RuntimeException("Could not open pipe file: {$this->fromServerPipe}");
        }
        if (false === posix_mkfifo($this->toServerPipe, 664)) {
            throw new RuntimeException("Could not create pipe file: {$this->toServerPipe}");
        }
        if (false === $this->toServerStream = fopen($this->toServerPipe, 'w+')) {
            throw new RuntimeException("Could not open pipe file: {$this->toServerPipe}");
        }

        Logger::info("Created pipes: {$this->fromServerPipe} {$this->toServerPipe}");
    }

    public function closeStreams(): void
    {
        if (is_resource($this->fromServerStream)) {
            fclose($this->fromServerStream);
        }
        if (is_resource($this->toServerStream)) {
            fclose($this->toServerStream);
        }

        Logger::info("Closed pipes: {$this->fromServerPipe} {$this->toServerPipe}");
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
}
