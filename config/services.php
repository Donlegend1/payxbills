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

    // Emails to facility users (login details, payment due, reminders, receipts). Keep "log" until real mail is set up.
    'messaging' => [
        'mailer' => env('MESSAGING_MAILER', 'log'),
    ],

    // SMS (App\Services\SmsService). Driver: "log" (nothing leaves the server) or "africastalking".
    'sms' => [
        'driver' => env('SMS_DRIVER', 'log'),
        // Prefix used to turn local numbers (0801...) into international ones (+234801...)
        'country_code' => env('SMS_COUNTRY_CODE', '234'),
    ],

    // Online payments. Use test keys (sk_test_...) until going live.
    // Webhook URL to set on the Paystack dashboard: /webhooks/paystack
    'paystack' => [
        'public_key' => env('PAYSTACK_PUBLIC_KEY'),
        'secret_key' => env('PAYSTACK_SECRET_KEY'),
    ],

    // Username "sandbox" uses the Africa's Talking sandbox API.
    'africastalking' => [
        'username' => env('AFRICASTALKING_USERNAME'),
        'api_key' => env('AFRICASTALKING_API_KEY'),
        // Registered sender ID or short code. Empty = the "SMS sender" setting.
        'from' => env('AFRICASTALKING_FROM'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
