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

    'enable_banking' => [
        'base_url' => env('ENABLE_BANKING_BASE_URL', 'https://api.enablebanking.com'),
        'app_id' => env('ENABLE_BANKING_APP_ID'),
        'private_key' => env(
            'ENABLE_BANKING_PRIVATE_KEY',
            env('ENABLE_BANKING_APP_ID') ? storage_path(env('ENABLE_BANKING_APP_ID').'.pem') : null,
        ),
        'aspsp_name' => env('ENABLE_BANKING_ASPSP_NAME'),
        'aspsp_country' => env('ENABLE_BANKING_ASPSP_COUNTRY', 'DK'),
        'psu_type' => env('ENABLE_BANKING_PSU_TYPE', 'personal'),
        'language' => env('ENABLE_BANKING_LANGUAGE', 'en'),
        'access_days' => (int) env('ENABLE_BANKING_ACCESS_DAYS', 90),
        'transaction_days' => (int) env('ENABLE_BANKING_TRANSACTION_DAYS', 30),
    ],

    'statement_embeddings' => [
        'provider' => env('STATEMENT_EMBEDDINGS_PROVIDER', 'openai'),
        'model' => env('STATEMENT_EMBEDDINGS_MODEL', 'text-embedding-3-small'),
        'dimensions' => (int) env('STATEMENT_EMBEDDINGS_DIMENSIONS', 1536),
        'batch_size' => (int) env('STATEMENT_EMBEDDINGS_BATCH_SIZE', 50),
    ],

];
