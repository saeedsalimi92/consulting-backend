<?php
return [
    'cancellation_penalty_percent'=>(int)env('BOOKING_CANCELLATION_PENALTY_PERCENT',10),
    'cancellation_penalty_basis'=>env('BOOKING_CANCELLATION_PENALTY_BASIS','paid'),
    'cancellation_cutoff_hours'=>(int)env('BOOKING_CANCELLATION_CUTOFF_HOURS',0),
    'reschedule_cutoff_hours'=>(int)env('BOOKING_RESCHEDULE_CUTOFF_HOURS',0),
    'max_client_reschedules'=>(int)env('BOOKING_MAX_CLIENT_RESCHEDULES',2),
    'payment_driver'=>env('BOOKING_PAYMENT_DRIVER', env('APP_ENV')==='production' ? 'zarinpal' : 'fake_bank'),
    'checkout_url'=>env('BOOKING_CHECKOUT_URL'),
    'fake_bank_return_url'=>env('BOOKING_FAKE_BANK_RETURN_URL','http://localhost:3000/fake-bank'),
    'checkout_token_minutes'=>(int)env('BOOKING_CHECKOUT_TOKEN_MINUTES',20),
    'hold_minutes'=>(int)env('BOOKING_HOLD_MINUTES',20),
    'connection_release_minutes'=>(int)env('BOOKING_CONNECTION_RELEASE_MINUTES',10),
    'session_timezone'=>env('BOOKING_TIMEZONE','Asia/Tehran'),
    'sms_driver'=>env('BOOKING_SMS_DRIVER','log'),
    'client_reset_url'=>env('BOOKING_CLIENT_RESET_URL'),
];
