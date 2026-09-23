<?php

declare(strict_types=1);

namespace LaSouris\CreditCheck\Laravel\Tests;

use InvalidArgumentException;
use LaSouris\CreditCheck\Laravel\CreditCheckerManager;
use LaSouris\CreditCheck\Laravel\Facades\CreditCheck;
use LaSouris\CreditCheck\Edr\CreditCheck\EdrCreditChecker;
use LaSouris\CreditCheck\Edr\CreditCheck\EdrPayloadMapper;
use LaSouris\CreditCheck\Sdk\Provider\CreditChecker;
use LaSouris\CreditCheck\Laravel\Tests\Fake\FakeCreditChecker;
use ReflectionProperty;

final class CreditCheckerManagerTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('app.url', 'https://admin.example.com');
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

    public function testWebhookUrlIsOmittedFromEdrDriverByDefault(): void
    {
        $checker = $this->app->make(CreditCheckerManager::class)->driver('edr');

        self::assertNull($this->mapperWebhookUrl($checker));
    }

    public function testWebhookUrlIsWiredIntoEdrDriverWhenUseInCreateIsEnabled(): void
    {
        $this->app['config']->set('credit-check.webhook.use_in_create', true);

        $checker = $this->app->make(CreditCheckerManager::class)->driver('edr');

        self::assertSame(
            'https://admin.example.com/webhooks/credit-check/edr',
            $this->mapperWebhookUrl($checker),
        );
    }

    public function testWebhookUrlHonoursACustomPrefix(): void
    {
        $this->app['config']->set('credit-check.webhook.use_in_create', true);
        $this->app['config']->set('credit-check.webhook.routing.prefix', 'inbound/edr-callbacks');

        $checker = $this->app->make(CreditCheckerManager::class)->driver('edr');

        self::assertSame(
            'https://admin.example.com/inbound/edr-callbacks/edr',
            $this->mapperWebhookUrl($checker),
        );
    }

    public function testUseInCreateAsAStringIsSentAsIs(): void
    {
        $this->app['config']->set('credit-check.webhook.use_in_create', 'https://public.example.com/hooks/edr');

        $checker = $this->app->make(CreditCheckerManager::class)->driver('edr');

        self::assertSame('https://public.example.com/hooks/edr', $this->mapperWebhookUrl($checker));
    }

    private function mapperWebhookUrl(EdrCreditChecker $checker): ?string
    {
        $mapperProperty = new ReflectionProperty(EdrCreditChecker::class, 'mapper');
        $mapper = $mapperProperty->getValue($checker);

        $webhookUrlProperty = new ReflectionProperty(EdrPayloadMapper::class, 'webhookUrl');

        return $webhookUrlProperty->getValue($mapper);
    }
}
