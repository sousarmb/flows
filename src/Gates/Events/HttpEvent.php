<?php

declare(strict_types=1);

namespace Flows\Gates\Events;

use Flows\Contracts\Gates\GateEvent as GateEventContract;
use Flows\Contracts\Gates\HttpEvent as HttpEventContract;
use Flows\Contracts\Gates\Stream as StreamContract;
use Flows\Facades\Config;
use Flows\Facades\Logger;
use Flows\Helpers\ResponseMessageToHttpRequest;
use Flows\Traits\Echos;
use Flows\Traits\Files;
use Flows\Traits\RandomString;
use RuntimeException;

/**
 * 
 * Event gate event, process HTTP requests received by helper HTTP server
 */
abstract class HttpEvent implements GateEventContract, HttpEventContract, StreamContract
{
    use Echos;
    use Files;
    use RandomString;

    const TIMEOUT = 600; // 5 minutes

    /**
     * @var string $handlerSrvSockFile Socket file path
     */
    private string $handlerSrvSockFile;

    /**
     * @var string $path
     */
    protected string $path;

    /**
     * @var array $allowedMethods
     */
    protected array $allowedMethods;

    /**
     * @var resource $handlerSrvSock Server to handle relayed requests from the HTTP handler server
     */
    private mixed $handlerSrvSock;

    /**
     * @var resource $client The HTTP handler server client request to relay the HTTP request it received
     */
    private mixed $client;

    /**
     * @var int $timeout In seconds, how long the HTTP server keeps this resource, default is TIMEOUT seconds
     */
    protected int $timeout;

    /**
     * @var bool $resourceClosed Prevent wait forever on socket connect to server when cleaning up resource.
     */
    private bool $resourceClosed = false;

    /**
     * Register event/resource with HTTP server
     */
    private function registerPathWithHandlerServer(): void
    {
        // This socket is used for PHP <-> Handler server communications
        $temp = sys_get_temp_dir() . DIRECTORY_SEPARATOR;
        $this->handlerSrvSockFile = $temp . $this->getHexadecimal(8, 'flows-', '.sock');
        // Fresh start
        @unlink($this->handlerSrvSockFile);
        // Register command
        $cmd = json_encode([
            'command' => 'register',
            'path' => $this->path,
            'socket_file' => $this->handlerSrvSockFile,
            'external_process_id' => INSTANCE_UID,
            'allowed_methods' => $this->allowedMethods,
            'timeout' => isset($this->timeout) ? $this->timeout : self::TIMEOUT
        ]) . "\n";
        $resp = $this->sendCommandToHandlerServer($cmd);
        if (!$resp['ok']) {
            throw new RuntimeException("Could not register path {$this->path}: {$resp['error']}");
        }

        Logger::info("Registered path: {$this->path}");
    }

    /**
     * Deregister event/resource with HTTP server
     */
    private function deregisterPathWithHandlerServer(): void
    {
        // Deregister command
        $cmd = json_encode([
            'command' => 'deregister',
            'path' => $this->path,
            'socket_file' => "",
            'external_process_id' => INSTANCE_UID,
            'allowed_methods' => [],
            'timeout' => 0
        ]) . "\n";
        $resp = $this->sendCommandToHandlerServer($cmd);
        if ($resp['ok']) {
            Logger::info("Deregistered path: {$this->path}");
        } else {
            // Fail silently: resource probably not there anymore or incorrect owner, just log this
            Logger::info("Could not deregister path {$this->path}: {$resp['error']}");
        }
    }

    /**
     * Register event/resource with HTTP server
     * Creates and binds to command (comms) socket where HTTP server listens to commands
     * 
     * @throws RuntimeException If unable to open command pipe file or register paths with HTTP server
     */
    private function sendCommandToHandlerServer(string $cmd): array
    {
        $cmdSockFile = Config::getApplicationSettings()->get('http.server.command_socket_path');
        $sock = socket_create(AF_UNIX, SOCK_STREAM, 0);
        if (!$sock) {
            throw new RuntimeException("Socket create fail: {$cmdSockFile}");
        }
        // Wait for handler server to create file (but not forever)
        $this->waitForFile($cmdSockFile);
        if (!@socket_connect($sock, $cmdSockFile)) {
            socket_close($sock);
            $msg = sprintf(
                'Socket connect %s fail: %s',
                $cmdSockFile,
                socket_strerror(socket_last_error($sock))
            );
            throw new RuntimeException($msg);
        }

        $len = strlen($cmd);
        $sent = socket_write($sock, $cmd, $len);
        if (false === $sent) {
            socket_close($sock);
            $msg = sprintf(
                'Error reading response from handler server: %s',
                socket_strerror(socket_last_error($sock))
            );
            throw new RuntimeException($msg);
        } elseif ($sent < $len) {
            socket_close($sock);
            throw new RuntimeException('Could not send whole command message to handler server');
        }

        $resp = socket_read($sock, 4096);
        socket_close($sock);
        if ($resp === false) {
            $msg = sprintf(
                'Error reading response from handler server: %s',
                socket_strerror(socket_last_error($sock))
            );
            throw new RuntimeException($msg);
        }
        if (!json_validate($resp)) {
            $msg = sprintf('Invalid JSON response from handler server: %s', json_last_error_msg());
            throw new RuntimeException($msg);
        }

        $resp = json_decode($resp, true);
        return $resp;
    }

    /**
     * Create a server so the handler server can relay requests it receives
     * 
     * @throws RuntimeException If unable to create server
     */
    private function createServerForRequestRelay(): void
    {
        // Remove old socket
        if (file_exists($this->handlerSrvSockFile)) {
            @unlink($this->handlerSrvSockFile);
        }
        // Create Unix domain socket server
        $this->handlerSrvSock = stream_socket_server(
            "unix://{$this->handlerSrvSockFile}",
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
        );
        if (!$this->handlerSrvSock) {
            throw new RuntimeException("Server creation failed: {$errstr} ({$errno})");
        }
        // Non-blocking server socket
        stream_set_blocking($this->handlerSrvSock, false);
    }

    /**
     * Close and remove socket used for communication with HTTP server
     */
    public function closeResource(): void
    {
        if ($this->resourceClosed) {
            return;
        }
        if ($this->pingHandlerServer()) {
            $this->deregisterPathWithHandlerServer();
        }
        if (
            isset($this->handlerSrvSock)
            && is_resource($this->handlerSrvSock)
        ) {
            fclose($this->handlerSrvSock);
            @unlink($this->handlerSrvSockFile);
            Logger::info("Closed socket: {$this->handlerSrvSockFile}");
        }

        $this->resourceClosed = true;
    }

    /**
     * Get the socket the HTTP server writes to when a request is received.
     * If communication socket doesn't exist create it.
     * 
     * @return resource 
     */
    public function getResource(): mixed
    {
        if (
            !isset($this->handlerSrvSockFile)
            || !isset($this->handlerSrvSock)
        ) {
            $this->registerPathWithHandlerServer();
            $this->createServerForRequestRelay();
        }
        // This stream will be passed to resolve() when a request that matches 
        // criteria (path + method(s)) arrives on the HTTP server
        return $this->handlerSrvSock;
    }

    public function __destruct()
    {
        $this->closeResource();
    }

    public function accepted(
        int $code,
        string $status,
        mixed $message
    ): bool {
        $resp = new ResponseMessageToHttpRequest(true, $code, $status, $message);
        if (false === fwrite($this->client, json_encode($resp) . "\n")) {
            $this->closeResource();
            throw new RuntimeException("Could not write \"accepted\" message to handler server");
        }

        fflush($this->client);
        return true;
    }

    public function tryAgain(
        int $code,
        string $status,
        mixed $message
    ): bool {
        $resp = new ResponseMessageToHttpRequest(false, $code, $status, $message);
        if (false === fwrite($this->client, json_encode($resp) . "\n")) {
            $this->closeResource();
            throw new RuntimeException('Could not write "try again" message to handler server');
        }

        fflush($this->client);
        return false;
    }

    public function acceptClient(): mixed
    {
        if (false === $this->client = stream_socket_accept($this->handlerSrvSock)) {
            throw new RuntimeException('Unable to accept client');
        }

        return $this->client;
    }

    abstract public function resolve(mixed $mixed = null): bool;
}
