<?php

declare(strict_types=1);

namespace LaSouris\CreditCheck\Laravel\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;
use LaSouris\CreditCheck\Sdk\Provider\WebhookMetadata;

final readonly class CreditCheckWebhookReceivedEvent
{
    use Dispatchable;

    /**
     * @param class-string $provider Originating provider's class-string (e.g. `EdrCreditChecker::class`).
     *                                Always set — compare with `=== EdrCreditChecker::class`; short name is
     *                                `$event->provider::NAME`.
     * @param Request $request Original HTTP request, for callers that need raw access (body, headers).
     * @param ?WebhookMetadata $metadata Provider-specific parsed fields (e.g. EDR's own `Metadata`),
     *                                   or null when the dispatching provider hasn't supplied one.
     */
    public function __construct(
        public string           $provider,
        public Request          $request,
        public ?WebhookMetadata $metadata = null,
    ) {
    }
}
