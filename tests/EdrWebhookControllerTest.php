<?php

declare(strict_types=1);

namespace LaSouris\CreditCheck\Laravel\Tests;

use Illuminate\Support\Facades\Event;
use LaSouris\CreditCheck\Edr\CreditCheck\EdrCreditChecker;
use LaSouris\CreditCheck\Edr\Webhook\Metadata;
use LaSouris\CreditCheck\Laravel\Events\CreditCheckWebhookReceivedEvent;
use LaSouris\CreditCheck\Laravel\Support\EdrWebhook;

final class EdrWebhookControllerTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('credit-check.webhook.enabled', true);
    }

    public function testIncomingWebhookDispatchesEvent(): void
    {
        Event::fake();

        $path = EdrWebhook::path($this->app['config']);
        $response = $this->postJson(
            "/{$path}?Ref=abc-123&orderId=ORDER-1&newStatus=Approved",
            ['status' => 'accepted'],
        );

        $response->assertOk();
        $response->assertJson(['ok' => true]);

        Event::assertDispatched(
            CreditCheckWebhookReceivedEvent::class,
            static function (CreditCheckWebhookReceivedEvent $event): bool {
                return $event->provider === EdrCreditChecker::class
                    && $event->metadata instanceof Metadata
                    && $event->metadata->reference === 'abc-123'
                    && $event->metadata->orderId === 'ORDER-1'
                    && $event->metadata->newStatus === 'Approved';
            },
        );
    }
}
