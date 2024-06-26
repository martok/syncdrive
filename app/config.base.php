<?php

return [
    'log' => [
        'file' => 'data/application.log',
    ],
    'db' => [
        'type' => 'sqlite',
        'host' => 'localhost',
        'database' => 'data/database.sqlite',
        'username' => '',
        'password' => '',
    ],
    'storage' => [
        'checksums' => ['SHA1'],
        'chunkSize' => '64M',
        'backends' => [
            [
                'intent' => ['temporary', 'storage'],
                'class' => 'App\ObjectStorage\FileBackend\FileBackend',
                'config' => [
                    'path' => 'data/blob',
                ],
            ],
        ],
    ],
    'site' => [
        'title' => 'SyncDrive',
        'byline' => '© 2023 Martok',
        'maintenance' => true,
        'readonly' => false,
        'registration' => true,
        'adminUsers' => [],
    ],
    'files' => [
        'trash_days' => 7,
        'versions' => [
            'max_days' => 365,
            'zero_byte_seconds' => 5,
            'intervals' => [
                // [$intervalEnd, $keepEvery]
                [             10,          2],
                [             60,         10],
                [           3600,         60],
                [          86400,       3600],
                [        2592000,      86400],
                [              -1,    604800]
            ]
        ]
    ],
    'thumbnails' => [
        'enabled' => true,
        'maxFileSize' => '10M',
        'resolutions' =>  [
            [256, 256]
        ],
    ],
    'tasks' => [
        // 'request' or 'cron' or 'webcron'
        'runMode' => 'request',
        'maxRunTime' => 100,
        'webtoken' => '123456789',
    ],
];