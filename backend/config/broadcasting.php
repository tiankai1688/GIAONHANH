<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Broadcaster
    |--------------------------------------------------------------------------
    |
    | Set BROADCAST_DRIVER to pusher / ably / log / null. "null" keeps the app
    | working with zero config (events simply aren't pushed); switch to pusher
    | or ably with credentials to enable real-time order alerts.
    |
    */

    'default' => env('BROADCAST_DRIVER', 'null'),

    'connections' => [

        'pusher' => [
            'driver'   => 'pusher',
            'key'      => env('PUSHER_APP_KEY'),
            'secret'   => env('PUSHER_APP_SECRET'),
            'app_id'   => env('PUSHER_APP_ID'),
            'options'  => [
                'host'     => env('PUSHER_HOST') ?: null,
                'port'     => env('PUSHER_PORT', 443),
                'scheme'   => env('PUSHER_SCHEME', 'https'),
                'encrypted' => true,
                'useTLS'   => env('PUSHER_SCHEME', 'https') === 'https',
            ],
        ],

        'ably' => [
            'driver' => 'ably',
            'key'    => env('ABLY_KEY'),
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

];
