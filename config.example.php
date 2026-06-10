<?php

return [
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'azuracast_reports',
        'user' => 'root',
        'pass' => '',
    ],
    'azuracast' => [
        'base_url' => 'https://azuracast.sere.plus/api',
        'api_key' => 'YOUR_AZURACAST_API_KEY',
        'timeout_seconds' => 20,
    ],
    'app' => [
        'default_timezone' => 'Pacific/Fiji',
        'snapshot_interval_minutes' => 3,
    ],
];
