<?php
return [
    'code_length' => 6,
    'code_ttl_seconds' => (int) env('AUTH_OTP_TTL_SECONDS', 300),
    'cooldown_seconds' => (int) env('AUTH_OTP_COOLDOWN_SECONDS', 60),
    'max_accepted_per_hour' => (int) env('AUTH_OTP_MAX_ACCEPTED_PER_HOUR', 3),
    'max_attempts' => (int) env('AUTH_OTP_MAX_ATTEMPTS', 5),
    'provider' => env('SMS_PROVIDER', 'sms_ir'),
    'dev_preview_enabled' => (bool) env('OTP_DEV_PREVIEW_ENABLED', false),
    'fake_sms_enabled' => (bool) env('SMS_FAKE_ENABLED', false),
    'sandbox_otp_template_id' => 123456,
    'templates' => [
        'otp' => env('SMS_IR_OTP_TEMPLATE_ID'),
        'booking' => env('SMS_IR_BOOKING_TEMPLATE_ID'),
        'reminder' => env('SMS_IR_REMINDER_TEMPLATE_ID'),
        'cancel' => env('SMS_IR_CANCEL_TEMPLATE_ID'),
        'reschedule' => env('SMS_IR_RESCHEDULE_TEMPLATE_ID'),
    ],
];
