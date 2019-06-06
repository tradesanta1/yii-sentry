<?php

return [
    'components' => [
        'log' => [
            'targets' => [
                [
                    'class' => \notamedia\sentry\SentryTarget::class,
                    'dsn' => '',
                    'levels' => ['error', 'warning'],
                    'context' => true // Write the context information. The default is true.
                ],
            ]
        ],
    ]
];
