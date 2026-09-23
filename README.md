# Credit Check — Laravel

Laravel integration for the credit-check SDK: a publishable config file, a service provider, a
typed driver manager and a facade for any `CreditChecker` provider (EDR and beyond).

## Install

The service provider and `CreditCheck` facade are auto-discovered. Publish the config with:

```bash
php artisan vendor:publish --tag=credit-check-config
```

Then configure providers in `config/credit-check.php` (EDR is wired by default) and set the
credentials via environment variables:

```php
// config/credit-check.php
return [
    // Provider name for CreditCheck::driver(); may stay null when only one is configured.
    'default' => env('CREDIT_CHECK_DRIVER'),

    'providers' => [
        [
            'class'  => \LaSouris\CreditCheck\Edr\CreditCheck\EdrCreditChecker::class,
            'config' => [
                'environment'       => env('EDR_ENVIRONMENT', 'uat'),   // uat | production
                'email'             => env('EDR_EMAIL'),
                'password'          => env('EDR_PASSWORD'),
                'api_version'       => env('EDR_API_VERSION', '1.0'),
            ],
        ],
        // add more providers here — each is available via CreditCheck::driver('<name>')
    ],
];
```

Bind PSR-18/17 services in your app (any implementation), e.g. `guzzlehttp/guzzle` +
`nyholm/psr7`, so the provider drivers can be constructed. The manager resolves and caches each
driver lazily on first use, so misconfigured providers you never call cost nothing.

## Token cache

The EDR JWT and its refresh token are stored in the Laravel cache, so every request and queue
worker reuses one token instead of logging in again. Both live in a single entry — they are
issued and rotated together — under a key that hashes the account and environment, so several
accounts never share one. The entry survives as long as the pair is usable: a JWT that expired
can still be refreshed instead of triggering a full re-login.

```php
// config/credit-check.php
'cache' => [
    'store'   => env('CREDIT_CHECK_CACHE_STORE'),                          // null = app default
    'prefix'  => env('CREDIT_CHECK_CACHE_PREFIX', 'credit-check:token'),
    'ttl'     => env('CREDIT_CHECK_CACHE_TTL'),                            // optional cap in seconds
    'enabled' => env('CREDIT_CHECK_CACHE_ENABLED', true),                  // false = per-request only
],
```

The same keys may be set per provider under its own `config.cache` to override the defaults.

**Security:** the entry holds live credentials. Use a store your application already trusts with
session data, and do not point it at a store shared with untrusted tenants. `ttl` caps how long
a token may sit at rest; `enabled => false` switches caching off entirely.

Cache problems never break a check: an unreadable or unwritable cache costs an extra login,
nothing more.

## Webhooks

EDR calls back on order status changes rather than making you poll. Two independent settings
control this, both off by default:

```php
// config/credit-check.php
'webhook' => [
    'enabled'       => env('CREDIT_CHECK_WEBHOOK_ENABLED', false),        // register the listener route
    'use_in_create' => env('CREDIT_CHECK_WEBHOOK_URL', false),            // send the callback URL on order creation
    'routing' => [
        'prefix'     => env('CREDIT_CHECK_WEBHOOK_PREFIX', 'webhooks/credit-check'),
        'middleware' => ['api'],
    ],
],
```

- `enabled` registers `POST /{routing.prefix}/edr` (`webhooks/credit-check/edr` by default), which
  dispatches `LaSouris\CreditCheck\Laravel\Events\CreditCheckWebhookReceivedEvent` (with `provider`
  and `request`) for your own listener to act on. Turn this on only in the application that
  actually receives the callback. `$event->metadata` carries the provider's own parsed fields —
  EDR's is `LaSouris\CreditCheck\Edr\Webhook\Metadata` (`reference`, `orderId`, `newStatus`, parsed
  from its `Ref`/`orderId`/`newStatus` callback query parameters).
- `use_in_create` is `bool|string`: `true` sends `statusChangeCallback` on order creation, built
  from this application's own `APP_URL`; a string is sent as-is instead — for when the submitting
  app's own `APP_URL` isn't the public address EDR should call (e.g. behind a gateway with its own
  domain). Turn this on in whichever application submits the order — e.g. an admin platform that
  creates checks but never runs the listener itself.

`enabled` and `use_in_create` are independent on purpose: the app that submits an order is often
not the same app that receives its callback, so one may be on with the other off (see
`LaSouris\CreditCheck\Laravel\Support\EdrWebhook`, the single source of truth both the listener
route and the derived create-payload URL are built from).

## Usage

```php
use LaSouris\CreditCheck\Laravel\Facades\CreditCheck;

CreditCheck::submitCheck($request);                       // default driver
CreditCheck::getResult($reference);                       // ->raw keeps the provider's own body

CreditCheck::driver('edr')->getChangedChecksSince($since);
```

Or inject the contract directly — it resolves to the default driver:

```php
public function __construct(private \LaSouris\CreditCheck\Sdk\Provider\CreditChecker $checker) {}
```

Register additional providers or test doubles at runtime with
`CreditCheck::extend('name', fn () => ...)` or `CreditCheck::set('name', $checker)` — handy for
swapping in a fake `CreditChecker` in feature tests.

## Errors

Every backend failure is normalised to the SDK's `Sdk\CreditCheck\Exception\Provider*` hierarchy
(all under `CreditCheckException`), so you catch the same types no matter which driver answered —
see the
[SDK README](../sdk#exceptions). Out-of-reach input (non-NL / non-EUR for EDR) is rejected as a
`ProviderValidationException` before any network call.
