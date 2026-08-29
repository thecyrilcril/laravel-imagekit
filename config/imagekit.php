<?php

declare(strict_types=1);

// Credentials (IMAGEKIT_PUBLIC_KEY, IMAGEKIT_PRIVATE_KEY, IMAGEKIT_URL_ENDPOINT)
// and HTTP settings live in the Client's config, config/imagekit-client.php.
// `php artisan imagekit:install` publishes this file and the Client's.
return [
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

    // Delivery-time transformations: what we SERVE. Keys are the Client's
    // aliases (width, focus, quality, ...) or ImageKit short codes (w, fo, q).
    'presets' => [
        'default' => ['quality' => 85, 'format' => 'auto'],
        'avatar' => ['width' => 200, 'height' => 200, 'focus' => 'face', 'quality' => 85, 'format' => 'auto'],
        'thumbnail' => ['width' => 250, 'height' => 250, 'focus' => 'auto', 'quality' => 80, 'format' => 'auto'],
    ],
];
