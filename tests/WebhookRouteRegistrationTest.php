<?php

declare(strict_types=1);

namespace LaSouris\CreditCheck\Laravel\Tests;

use LaSouris\CreditCheck\Laravel\Support\EdrWebhook;

final class WebhookRouteRegistrationTest extends TestCase
{
    public function testWebhookRouteIsNotRegisteredByDefault(): void
    {
        $response = $this->postJson('/' . EdrWebhook::DEFAULT_PREFIX . '/edr?reference=abc-123', []);

        $response->assertNotFound();
    }
}
