<?php

declare(strict_types=1);

namespace LaSouris\CreditCheck\Laravel\Tests\Fake;

use DateTimeInterface;
use LaSouris\CreditCheck\Sdk\Provider\Capability;
use LaSouris\CreditCheck\Sdk\Provider\CreditChecker;
use LaSouris\CreditCheck\Sdk\Provider\Provider;
use LaSouris\CreditCheck\Sdk\Request\CreateCreditCheck;
use LaSouris\CreditCheck\Sdk\Response\ChangedChecksResponse;
use LaSouris\CreditCheck\Sdk\Response\CheckStatus;
use LaSouris\CreditCheck\Sdk\Response\CreateCreditCheckResponse;
use LaSouris\CreditCheck\Sdk\Response\Decision;
use LaSouris\CreditCheck\Sdk\Response\GetCreditCheckResponse;

/**
 * Minimal in-memory CreditChecker for the Laravel integration tests.
 */
#[Provider(
    name: 'fake',
    countries: ['NL'],
    currencies: ['EUR'],
    capabilities: [Capability::CREATE_CHECK, Capability::GET_RESULT],
)]
final class FakeCreditChecker implements CreditChecker
{
    public function submitCheck(CreateCreditCheck $request): CreateCreditCheckResponse
    {
        return new CreateCreditCheckResponse('fake', 'CHECK-1', CheckStatus::Submitted);
    }

    public function getResult(string $reference): GetCreditCheckResponse
    {
        return new GetCreditCheckResponse(
            'fake',
            $reference,
            Decision::Approved,
            CheckStatus::Completed,
        );
    }

    public function getChangedChecksSince(DateTimeInterface $since): ChangedChecksResponse
    {
        return new ChangedChecksResponse('fake', []);
    }
}
