<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],
    'sms_ir' => [
        'mode' => env('SMS_IR_MODE', 'sandbox'),
        'sandbox_api_key' => env('SMS_IR_SANDBOX_API_KEY'),
        'production_api_key' => env('SMS_IR_API_KEY'),
        'base_url' => env('SMS_IR_BASE_URL', 'https://api.sms.ir/v1'),
        'live_send_enabled' => (bool) env('SMS_IR_LIVE_SEND_ENABLED', false),
    ],

];
