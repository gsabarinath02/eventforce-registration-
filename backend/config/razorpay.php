<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Razorpay Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for Razorpay payment gateway integration.
    | This includes API credentials, webhook settings, and environment configuration.
    |
    */

    'key_id' => env('RAZORPAY_KEY_ID'),
    'key_secret' => env('RAZORPAY_KEY_SECRET'),
    'webhook_secret' => env('RAZORPAY_WEBHOOK_SECRET'),
    'environment' => env('RAZORPAY_ENVIRONMENT', 'test'),

    /*
    |--------------------------------------------------------------------------
    | API Configuration
    |--------------------------------------------------------------------------
    |
    | Razorpay API endpoints and configuration
    |
    */
    'api' => [
        'base_url' => 'https://api.razorpay.com/v1/',
        'timeout' => 30,
        'retry_attempts' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhook Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for handling Razorpay webhooks
    |
    */
    'webhooks' => [
        'events' => [
            'payment.authorized',
            'payment.captured',
            'payment.failed',
            'refund.processed',
        ],
        'signature_header' => 'X-Razorpay-Signature',
    ],

    /*
    |--------------------------------------------------------------------------
    | Currency Configuration
    |--------------------------------------------------------------------------
    |
    | Supported currencies for Razorpay payments
    |
    */
    'supported_currencies' => [
        'INR', 'USD', 'EUR', 'GBP', 'AUD', 'CAD', 'SGD', 'AED', 'MYR'
    ],

    /*
    |--------------------------------------------------------------------------
    | Order Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for Razorpay orders
    |
    */
    'orders' => [
        'receipt_prefix' => 'order_',
        'notes_max_length' => 512,
    ],
];