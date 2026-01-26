<?php

declare(strict_types=1);

namespace Flows\Traits;

use Flows\Facades\Config;

trait Echos
{
    public function echoLocalHttpServer(): bool
    {
        $urlEcho = sprintf('http://127.0.0.1:%s/echo', Config::getApplicationSettings()->get('http.server.listen_on'));
        $ch = curl_init($urlEcho);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        return false === $response
            ? false // server not there
            : (bool)json_decode($response); // server there
    }
}
