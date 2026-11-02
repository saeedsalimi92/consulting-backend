<?php
return [
    'enabled' => (bool) env('RECAPTCHA_ENABLED', true),
    'site_key' => env('RECAPTCHA_SITE_KEY'),
    'secret_key' => env('RECAPTCHA_SECRET_KEY'),
    'min_score' => (float) env('RECAPTCHA_MIN_SCORE', 0.5),
    'allowed_hostnames' => array_values(array_filter(array_map('trim', explode(',', (string) env('RECAPTCHA_ALLOWED_HOSTNAMES', 'localhost,127.0.0.1'))))),
];
