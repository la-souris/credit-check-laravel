<?php

namespace LaSouris\CreditCheck\Laravel\Http\Controllers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LaSouris\CreditCheck\Edr\CreditCheck\EdrCreditChecker;
use LaSouris\CreditCheck\Edr\Webhook\Metadata;
use LaSouris\CreditCheck\Laravel\Events\CreditCheckWebhookReceivedEvent;

final readonly class EdrWebhookController
{
    public function __construct(private Dispatcher $events)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $metadata = $this->parseRequest($request);

        $this->events->dispatch(new CreditCheckWebhookReceivedEvent(
            provider: EdrCreditChecker::class,
            request: $request,
            metadata: $metadata,
        ));

        return new JsonResponse(['ok' => true]);
    }

    private function parseRequest(Request $request): Metadata
    {
        return new Metadata(
            reference: $request->query('reference'),
            orderId: $request->query('orderId'),
            newStatus: $request->query('newStatus'),
        );
    }
}