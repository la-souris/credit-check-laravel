<?php

declare(strict_types=1);

namespace LaSouris\CreditCheck\Laravel\Tests;

use DateTimeImmutable;
use Illuminate\Support\Facades\Cache;
use LaSouris\CreditCheck\Edr\CreditCheck\EdrCreditChecker;
use LaSouris\CreditCheck\Laravel\CreditCheckerManager;
use LaSouris\CreditCheck\Laravel\Tests\Fake\FakeHttpClient;
use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * The JWT and refresh token must survive a request, so a second request reuses them
 * instead of logging in again.
 */
final class TokenCachingTest extends TestCase
{
    private FakeHttpClient $http;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $this->http = new FakeHttpClient();
        $app->instance(ClientInterface::class, $this->http);

        $app['config']->set('cache.default', 'array');
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

    protected function setUp(): void
    {
        parent::setUp();

        Cache::store('array')->clear();
    }

    public function testTheSecondRequestReusesTheCachedToken(): void
    {
        $this->http->queue($this->tokenResponse());
        $this->http->queue($this->changedIdsResponse());

        $this->newRequest()->getChangedChecksSince(new DateTimeImmutable('-1 day'));

        self::assertCount(2, $this->http->requests, 'First call logs in and then queries.');
        self::assertStringContainsString('GenerateTokenByCredentials', $this->http->uris()[0]);

        // A fresh manager stands in for the next request/worker: no login this time.
        $this->http->queue($this->changedIdsResponse());
        $this->newRequest()->getChangedChecksSince(new DateTimeImmutable('-1 day'));

        self::assertCount(3, $this->http->requests);
        self::assertStringNotContainsString('identity', $this->http->uris()[2]);
        self::assertSame('Bearer cached-jwt', $this->http->requests[2]->getHeaderLine('Authorization'));
    }

    public function testTheCachedEntryHoldsBothTokens(): void
    {
        $this->http->queue($this->tokenResponse());
        $this->http->queue($this->changedIdsResponse());

        $this->newRequest()->getChangedChecksSince(new DateTimeImmutable('-1 day'));

        $entry = $this->cachedToken();
        self::assertSame('cached-jwt', $entry['value']);
        self::assertSame('cached-refresh', $entry['refreshToken']);
    }

    public function testCachingCanBeSwitchedOff(): void
    {
        config()->set('credit-check.cache.enabled', false);

        $this->http->queue($this->tokenResponse());
        $this->http->queue($this->changedIdsResponse());
        $this->newRequest()->getChangedChecksSince(new DateTimeImmutable('-1 day'));

        self::assertNull($this->cachedToken(), 'Nothing may be cached when caching is off.');

        // Without a shared store the next request has to log in again.
        $this->http->queue($this->tokenResponse());
        $this->http->queue($this->changedIdsResponse());
        $this->newRequest()->getChangedChecksSince(new DateTimeImmutable('-1 day'));

        self::assertStringContainsString('GenerateTokenByCredentials', $this->http->uris()[2]);
    }

    public function testDifferentAccountsDoNotShareAnEntry(): void
    {
        $this->http->queue($this->tokenResponse());
        $this->http->queue($this->changedIdsResponse());
        $this->newRequest()->getChangedChecksSince(new DateTimeImmutable('-1 day'));

        config()->set('credit-check.providers.0.config.email', 'other@example.com');

        $this->http->queue($this->tokenResponse(['jwToken' => 'other-jwt']));
        $this->http->queue($this->changedIdsResponse());
        $this->newRequest()->getChangedChecksSince(new DateTimeImmutable('-1 day'));

        self::assertStringContainsString('GenerateTokenByCredentials', $this->http->uris()[2]);
        self::assertSame('Bearer other-jwt', $this->http->requests[3]->getHeaderLine('Authorization'));
    }

    /**
     * A manager built from scratch — the same state a new request or queue worker starts from.
     */
    private function newRequest(): EdrCreditChecker
    {
        $checker = (new CreditCheckerManager($this->app))->driver('edr');
        self::assertInstanceOf(EdrCreditChecker::class, $checker);

        return $checker;
    }

    /**
     * @return array<string, mixed>|null The single cached token entry, whatever its key.
     */
    private function cachedToken(): ?array
    {
        foreach (Cache::store('array')->getStore()->all() as $key => $entry) {
            if (str_starts_with($key, 'credit-check:token:')) {
                return $entry['value'];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function tokenResponse(array $overrides = []): ResponseInterface
    {
        return $this->jsonResponse([
            'succeeded' => true,
            'data' => array_replace([
                'jwToken' => 'cached-jwt',
                'expiresOn' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600),
                'refreshToken' => 'cached-refresh',
                'refreshTokenExpiresOn' => gmdate('Y-m-d\TH:i:s\Z', time() + 86400),
            ], $overrides),
        ]);
    }

    private function changedIdsResponse(): ResponseInterface
    {
        return $this->jsonResponse([4242]);
    }

    private function jsonResponse(mixed $body): ResponseInterface
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
    }
}
