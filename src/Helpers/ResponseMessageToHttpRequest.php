<?php

declare(strict_types=1);

namespace Flows\Helpers;

use Collectibles\IO;
use JsonSerializable;

readonly class ResponseMessageToHttpRequest extends IO implements JsonSerializable
{
    /**
     * @param bool $ok  External process signal, FALSE => respond with 400, TRUE => 200
     * @param int $code Reserved for custom code
     * @param string $status Reserved for custom status message
     * @param string $message Reserved for custom message
     */
    public function __construct(
        private bool $ok,
        private int $code,
        private string $status,
        private string $message
    ) {}

    /**
     * JSON object representation, plus instance unique identifier
     */
    public function jsonSerialize(): mixed
    {
        return [
            'ok' => $this->ok,
            'code' => $this->code,
            'status' => $this->status,
            'message' => $this->message,
            'instance_uid' => INSTANCE_UID
        ];
    }
}
