<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Guardian Authentication Package Configuration
    |--------------------------------------------------------------------------
    |
    | This configuration file allows you to customize the Guardian package's
    | authentication settings, including Socialite providers, 2FA methods,
    | API behavior, password reset, role management, and impersonation.
    |
    */

    // User model configuration
    'user_model' => env('GUARDIAN_USER_MODEL', 'App\Models\User'), // The User model class
    'use_uuid' => env('GUARDIAN_USE_UUID', false), // Use UUIDs for user IDs

    // API settings
    'api' => [
        'prefix' => 'api/auth', // Prefix for API routes
        'middleware' => ['api', 'auth:sanctum'], // Middleware for protected routes
    ],

    // Socialite providers
    'socialite' => [
        'drivers' => [
            'google' => [
                'client_id' => env('GOOGLE_CLIENT_ID'),
                'client_secret' => env('GOOGLE_CLIENT_SECRET'),
                'redirect' => env('GOOGLE_REDIRECT_URI'),
            ],
            'telegram' => [
                'bot_token' => env('TELEGRAM_BOT_TOKEN'),
                'redirect' => env('TELEGRAM_REDIRECT_URI'),
            ],
        ],
    ],

    // 2FA settings
    'two_factor' => [
        'enabled' => true,
        'methods' => ['email', 'sms', 'totp'],
        'sms_provider' => 'twilio',

        'twilio' => [
            'account_sid' => env('TWILIO_SID'),
            'auth_token' => env('TWILIO_AUTH_TOKEN'),
            'from' => env('TWILIO_FROM'),
        ],

        'vonage' => [
            'key' => env('VONAGE_KEY'),
            'secret' => env('VONAGE_SECRET'),
            'from' => env('VONAGE_SMS_FROM'),
        ],

        'code_length' => 6,
        'code_expiry' => 300, // 5 minutes
        'rate_limit' => [
            'attempts' => 5,
            'window' => 60,
        ],
        'totp' => [
            'issuer' => env('APP_NAME', 'Guardian'),
            'digits' => 6,
            'period' => 30,
        ],
    ],
    
    // Password reset settings
    'password_reset' => [
        'token_expiry' => 3600, // 1 hour
        'rate_limit' => [
            'attempts' => 5,
            'window' => 60,
        ],
    ],

    // Role and permission settings
    'roles' => [
        'default_role' => 'user',
        'admin_role' => 'admin',
        'guards' => ['web', 'api'],
        'middleware' => [
            'role' => 'guardian_role',
            'permission' => 'guardian_permission',
        ],
    ],

    // Impersonation settings
    'impersonation' => [
        'enabled' => true,
        'max_duration' => 3600, // 1 hour
    ],
];