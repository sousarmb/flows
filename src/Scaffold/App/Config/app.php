<?php

declare(strict_types=1);

return [
    'gate' => [
        'on_branch' => [
            'keep_io' => false,
        ]
    ],
    'http' => [
        'server' => [
            'address' => '0.0.0.0:9090',
            'command_socket_path' => sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'flows-command.sock',
            'timeout_read_external_process' => 30,
        ]
    ],
    'stop' => [
        'on_offload_error' => false,
        'on_no_event_handler' => false,
    ],
];
