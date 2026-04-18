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

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model_mapping' => env('ANTHROPIC_MODEL_MAPPING', 'claude-opus-4-7'),
        'model_translation' => env('ANTHROPIC_MODEL_TRANSLATION', 'claude-haiku-4-5-20251001'),
    ],

    'microsoft_translator' => [
        'key' => env('MICROSOFT_TRANSLATOR_KEY'),
        'endpoint' => env('MICROSOFT_TRANSLATOR_ENDPOINT', 'https://api.cognitive.microsofttranslator.com'),
        'region' => env('MICROSOFT_TRANSLATOR_REGION', 'westeurope'),
    ],

    'deepl' => [
        'api_key' => env('DEEPL_API_KEY'),
        'free' => env('DEEPL_API_FREE', true),
    ],

    'xai' => [
        'api_key' => env('XAI_API_KEY'),
        'model' => env('XAI_MODEL', 'grok-3-mini'),
    ],

];
