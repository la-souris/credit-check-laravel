<?php

declare(strict_types=1);

namespace LaSouris\CreditCheck\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use LaSouris\CreditCheck\Laravel\CreditCheckerManager;

/**
 * @method static \LaSouris\CreditCheck\Sdk\Provider\CreditChecker driver(?string $name = null)
 * @method static \LaSouris\CreditCheck\Sdk\Response\Response<\LaSouris\CreditCheck\Sdk\Result\CreditCheckReceipt> submitCheck(\LaSouris\CreditCheck\Sdk\Request\CreateCreditCheckRequest $request)
 * @method static \LaSouris\CreditCheck\Sdk\Response\Response<\LaSouris\CreditCheck\Sdk\Result\CreditCheckResult> getResult(string $reference)
 * @method static \LaSouris\CreditCheck\Sdk\Response\Response<list<string>> getChangedChecksSince(\DateTimeInterface $since)
 * @method static CreditCheckerManager set(string $name, \LaSouris\CreditCheck\Sdk\Provider\CreditChecker $provider)
 * @method static CreditCheckerManager extend(string $name, \Closure $factory)
 * @method static string getDefaultDriver()
 *
 * @see CreditCheckerManager
 */
final class CreditCheck extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return CreditCheckerManager::class;
    }
}
