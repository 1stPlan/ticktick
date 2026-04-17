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

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | OpenAI API
    |--------------------------------------------------------------------------
    */
    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'organization' => env('OPENAI_ORGANIZATION'),
        'embedding_model' => env('OPENAI_EMBEDDING_MODEL', 'text-embedding-3-small'),
        'chat_model' => env('OPENAI_CHAT_MODEL', 'gpt-4o-mini'),
    ],

    /*
    |--------------------------------------------------------------------------
    | LINE Messaging API
    |--------------------------------------------------------------------------
    */
    'line' => [
        'channel_secret' => env('LINE_CHANNEL_SECRET'),
        'channel_access_token' => env('LINE_CHANNEL_ACCESS_TOKEN'),
    ],

    /*
    |--------------------------------------------------------------------------
    | TickTick API
    |--------------------------------------------------------------------------
    */
    'ticktick' => [
        'client_id' => env('TICKTICK_CLIENT_ID'),
        'client_secret' => env('TICKTICK_CLIENT_SECRET'),
        'redirect_uri' => rtrim(env('TICKTICK_REDIRECT_URI', env('APP_URL').'/ticktick/callback')),
        /** Web のリスト URL の #p/ の直後（例: 69aa7607ba9f51142a1f8072）。DB の default_project_id 未設定時のフォールバック */
        'line_default_project_id' => env('TICKTICK_LINE_DEFAULT_PROJECT_ID'),
    ],

];
