<?php

declare(strict_types=1);

namespace Flows\Traits;

use Flows\Facades\Config;
use InvalidArgumentException;

trait Echos
{
    /**
     * Check if HTTP handler server is running
     * 
     * @param string|null $address IP address plus port. Protocol is always HTTP. Defaults to configuration "http.server.address" value
     * @throws InvalidArgumentException If provided address is not valid
     * @return bool TRUE => server reachable, FALSE => server not reachable
     */
    public function pingHandlerServer(?string $address = null): bool
    {
        $urlPing = sprintf("http://%s/ping", $address ?? Config::getApplicationSettings()->get('http.server.address'));
        if (false === filter_var($urlPing, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('Invalid URL for HTTP handler server');
        }

        $ch = curl_init($urlPing);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        unset($ch);
        return false === $response
            ? false // server not there
            : (bool)json_decode($response); // server there
    }

    /**
     * Check if host is reachable (CONNECT only)
     * 
     * @param string $address Host address to connect to
     * @throws InvalidArgumentException If provided address is not valid
     * @return bool TRUE => server reachable, FALSE => server not reachable
     */
    public function connectWithHost(string $address): bool
    {
        if (false === filter_var($address, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('Invalid host URL');
        }

        $ch = curl_init($address);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
        curl_setopt($ch, CURLOPT_CONNECT_ONLY, true);
        $status = curl_exec($ch);
        unset($ch);
        return $status;
    }
}
