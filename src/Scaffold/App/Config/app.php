<?php

declare(strict_types=1);

return [
    'gate' => [
        'on_branch' => [
            // When flow branches, keep previous process output
            'keep_io' => false,
        ],
    ],
    'http' => [
        'server' => [
            // HTTP helper server command socket path (for event gates with HTTP events)
            'command_socket_path' => sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'flows-command.sock',
            // HTTP helper server listens on this address
            'address' => '0.0.0.0:9090',
            // Seconds, HTTP handler server timeout for a response from a Flows process
            'timeout_read_external_process' => 30,
        ],
    ],
    'stop' => [
        // Stop when an event that has no registered handler is triggered
        'on_no_event_handler' => false,
        // Stop when an error occurs trying to offload a process 
        'on_offload_error' => true,
    ],
    /* Microseconds, timeout for a Flows process to wait for any file to be created 
     * by an external process, internally used to wait for the command socket to be created
     * during HTTP handler server boot (this value is used in a trait that "sleep"s the PHP thread).
     * Low value when running on fast hardware */
    'wait_timeout_for_files' => 300,
];
