<?php

declare(strict_types=1);

use LaSouris\CreditCheck\Edr\CreditCheck\EdrCreditChecker;

return [

    /*
    |--------------------------------------------------------------------------
    | Default provider
    |--------------------------------------------------------------------------
    |
    | The provider name used when none is given to CreditCheck::driver(). A
    | provider's name is the NAME constant on its class (e.g. "edr"). When
    | exactly one provider is configured this may be left null.
    |
    */
    'default' => env('CREDIT_CHECK_DRIVER'),

    /*
    |--------------------------------------------------------------------------
    | Token cache
    |--------------------------------------------------------------------------
    |
    | Access token (JWT) and refresh token are kept in the Laravel cache, so all
    | requests and queue workers reuse one token instead of logging in again.
    |
    | store   The cache store to use; null uses the application default.
    | prefix  Key prefix. The account itself is hashed into the key, so several
    |         accounts or environments never share an entry.
    | ttl     Optional cap in seconds on how long a token may stay cached. By
    |         default the entry lives exactly as long as the tokens are usable.
    | enabled Set to false to keep tokens in memory for the request only.
    |
    | Note: the cached entry contains live credentials — use a store your app
    | already trusts with session data, and avoid shared/unsecured stores.
    |
    */
    'cache' => [
        'store' => env('CREDIT_CHECK_CACHE_STORE'),
        'prefix' => env('CREDIT_CHECK_CACHE_PREFIX', 'credit-check:token'),
        'ttl' => env('CREDIT_CHECK_CACHE_TTL'),
        'enabled' => env('CREDIT_CHECK_CACHE_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Providers
    |--------------------------------------------------------------------------
    */
    'providers' => [
        [
            'class' => EdrCreditChecker::class,
            'config' => [
                'environment' => env('EDR_ENVIRONMENT', 'uat'),
                'email' => env('EDR_EMAIL'),
                'password' => env('EDR_PASSWORD'),
                'api_version' => env('EDR_API_VERSION', '1.0'),
            ],
        ],
    ],

];
