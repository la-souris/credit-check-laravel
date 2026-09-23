<?php

declare(strict_types=1);

namespace LaSouris\CreditCheck\Laravel\Tests;

use LaSouris\CreditCheck\Laravel\Support\EdrWebhook;

final class EdrWebhookCustomPrefixTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('credit-check.webhook.enabled', true);
        $app['config']->set('credit-check.webhook.routing.prefix', 'inbound/edr-callbacks');
    }

    public function testRouteIsRegisteredUnderTheConfiguredPrefix(): void
    {
        $this->postJson('/inbound/edr-callbacks/edr?reference=abc-123', [])->assertOk();
    }

    public function testDefaultPrefixIsNoLongerRegistered(): void
    {
        $this->postJson('/' . EdrWebhook::DEFAULT_PREFIX . '/edr?reference=abc-123', [])->assertNotFound();
    }
}
