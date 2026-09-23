<?php

declare(strict_types=1);

namespace LaSouris\CreditCheck\Laravel\Support;

use Illuminate\Contracts\Config\Repository;

/**
 * Single source of truth for EDR's callback endpoint: the route registered so our app can
 * receive it, and the absolute URL sent to EDR on order creation, must always name the same
 * endpoint. The host comes from this application's own `app.url` config (APP_URL) rather than
 * the current request — order creation can happen from a queue worker or console command with
 * no inbound request to infer a host from — while the path prefix in front of it is separately
 * configurable, so an install can namespace its own route however it needs to.
 */
final class EdrWebhook
{
    public const string DEFAULT_PREFIX = 'webhooks/credit-check';

    public const string SUFFIX = 'edr';

    public static function prefix(Repository $config): string
    {
        return trim((string) $config->get('credit-check.webhook.routing.prefix', self::DEFAULT_PREFIX), '/');
    }

    public static function path(Repository $config): string
    {
        return self::prefix($config) . '/' . self::SUFFIX;
    }

    public static function url(Repository $config): string
    {
        $host = rtrim((string) $config->get('app.url', 'http://localhost'), '/');

        return $host . '/' . self::path($config);
    }
}
