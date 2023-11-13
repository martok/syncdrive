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
        'class' => 'App\ObjectStorage\FileBackend\FileBackend',
        'chunkSize' => '64M',
        'path' => 'data/blob',
    ],
    'site' => [
        'title' => 'My Storage Platform',
        'owner' => 'ACME Corp.',
        'maintenance' => false,
        'readonly' => false,
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
];