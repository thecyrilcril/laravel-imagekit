<?php

declare(strict_types=1);

return [
    'public_key' => env('IMAGEKIT_PUBLIC_KEY'),
    'private_key' => env('IMAGEKIT_PRIVATE_KEY'),
    'url_endpoint' => env('IMAGEKIT_URL_ENDPOINT'),

    'queue' => [
        'connection' => env('IMAGEKIT_QUEUE_CONNECTION'),
        'name' => env('IMAGEKIT_QUEUE', 'imagekit'),
        'tries' => 3,
        'backoff' => 5,
    ],

    'folder' => env('IMAGEKIT_FOLDER', 'uploads'),

    // Upload-time compression: what we STORE.
    // 'await' => true uploads before the response returns, so an API caller
    // receives the final CDN URL. false queues it, which suits web requests.
    'profiles' => [
        'default' => ['compress' => true, 'max_edge' => 2000, 'quality' => 90, 'format' => null, 'await' => false],
        'avatar' => ['compress' => true, 'max_edge' => 2000, 'quality' => 90, 'format' => null, 'await' => false],
        'document' => ['compress' => false, 'await' => false],
    ],

    // Delivery-time transformations: what we SERVE.
    'presets' => [
        'default' => ['quality' => 85, 'format' => 'auto'],
        'avatar' => ['width' => 200, 'height' => 200, 'focus' => 'face', 'quality' => 85, 'format' => 'auto'],
        'thumbnail' => ['width' => 250, 'height' => 250, 'focus' => 'auto', 'quality' => 80, 'format' => 'auto'],
    ],
];
