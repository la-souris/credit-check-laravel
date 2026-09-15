<?php

declare(strict_types=1);

namespace LaSouris\CreditCheck\Laravel\Tests;

use InvalidArgumentException;
use LaSouris\CreditCheck\Laravel\CreditCheckerManager;
use LaSouris\CreditCheck\Laravel\Facades\CreditCheck;
use LaSouris\CreditCheck\Edr\CreditCheck\EdrCreditChecker;
use LaSouris\CreditCheck\Sdk\Provider\CreditChecker;
use LaSouris\CreditCheck\Laravel\Tests\Fake\FakeCreditChecker;

final class CreditCheckerManagerTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('credit-check.providers', [
            [
                'class' => EdrCreditChecker::class,
                'config' => [
                    'environment' => 'uat',
                    'email' => 'api@example.com',
                    'password' => 'secret',
                ],
            ],
        ]);
    }

    public function testResolvesEdrDriver(): void
    {
        $checker = $this->app->make(CreditCheckerManager::class)->driver('edr');

        self::assertInstanceOf(EdrCreditChecker::class, $checker);
    }

    public function testDefaultsToTheSingleConfiguredProvider(): void
    {
        $manager = $this->app->make(CreditCheckerManager::class);

        self::assertSame('edr', $manager->getDefaultDriver());
        self::assertSame(['edr'], $manager->configuredDrivers());
    }

    public function testContractResolvesToDefaultDriver(): void
    {
        self::assertInstanceOf(EdrCreditChecker::class, $this->app->make(CreditChecker::class));
    }

    public function testExtendRegistersCustomDriver(): void
    {
        $manager = $this->app->make(CreditCheckerManager::class);
        $manager->extend('fake', static fn (): CreditChecker => new FakeCreditChecker());

        self::assertInstanceOf(FakeCreditChecker::class, $manager->driver('fake'));
    }

    public function testFacadeProxiesToDefaultDriver(): void
    {
        CreditCheck::set('fake', new FakeCreditChecker());
        $this->app['config']->set('credit-check.default', 'fake');

        self::assertSame('CHECK-1', CreditCheck::getResult('CHECK-1')->reference);
    }

    public function testDriverNameIsReadFromTheProviderAttribute(): void
    {
        $manager = $this->app->make(CreditCheckerManager::class);
        $manager->extend('fake', static fn (): CreditChecker => new FakeCreditChecker());

        // 'edr' comes from #[Provider] on EdrCreditChecker, with no instance constructed.
        self::assertSame(['edr'], $manager->configuredDrivers());
    }

    public function testUnknownDriverThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->app->make(CreditCheckerManager::class)->driver('nope');
    }
}
