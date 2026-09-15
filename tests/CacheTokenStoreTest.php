<?php

declare(strict_types=1);

namespace LaSouris\CreditCheck\Laravel\Tests;

use Illuminate\Cache\ArrayStore;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use LaSouris\CreditCheck\Edr\Auth\AccessToken;
use LaSouris\CreditCheck\Laravel\Auth\CacheTokenStore;

final class CacheTokenStoreTest extends TestCase
{
    private Repository $cache;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cache = Cache::store('array');
        $this->cache->clear();
    }

    public function testPutAndGetRoundTripBothTokens(): void
    {
        $store = $this->store();
        $store->put(new AccessToken('jwt', time() + 3600, 'refresh', time() + 86400));

        $token = $store->get();

        self::assertNotNull($token);
        self::assertSame('jwt', $token->value);
        self::assertSame('refresh', $token->refreshToken);
    }

    public function testMissingEntryReadsAsNoToken(): void
    {
        self::assertNull($this->store()->get());
    }

    public function testForeignOrStaleEntryReadsAsNoToken(): void
    {
        $this->cache->put('tokens:edr', ['something' => 'else'], 60);

        self::assertNull($this->store()->get());
    }

    public function testEntryLivesAsLongAsTheRefreshTokenIsUsable(): void
    {
        $this->store()->put(new AccessToken('jwt', time() + 600, 'refresh', time() + 86400));

        // The JWT dies in 10 minutes, but the pair stays cached so it can still be refreshed.
        self::assertGreaterThan(600, $this->ttl());
    }

    public function testTtlIsCappedByTheConfiguredMaximum(): void
    {
        $this->store(maxTtl: 120)->put(new AccessToken('jwt', time() + 3600, 'refresh', time() + 86400));

        self::assertLessThanOrEqual(120, $this->ttl());
    }

    public function testAlreadyExpiredTokenIsNotStored(): void
    {
        $this->store()->put(new AccessToken('jwt', time() - 10));

        self::assertNull($this->cache->get('tokens:edr'));
    }

    /**
     * After a 401 the provider writes the pair back with an expired JWT — the entry must
     * survive on the refresh token's lifetime so the next call can still refresh.
     */
    public function testExpiredJwtWithAUsableRefreshTokenStaysCached(): void
    {
        $store = $this->store();
        $store->put(new AccessToken('jwt', time() - 10, 'refresh', time() + 86400));

        $token = $store->get();

        self::assertNotNull($token);
        self::assertSame('refresh', $token->refreshToken);
        self::assertGreaterThan(0, $this->ttl());
    }

    public function testStoringAnUnusableTokenDropsTheOlderEntry(): void
    {
        $store = $this->store();
        $store->put(new AccessToken('jwt', time() + 3600, 'refresh', time() + 86400));

        $store->put(new AccessToken('jwt', time() - 10));

        self::assertNull($store->get());
    }

    public function testForgetRemovesTheEntry(): void
    {
        $store = $this->store();
        $store->put(new AccessToken('jwt', time() + 3600));

        $store->forget();

        self::assertNull($store->get());
    }

    private function store(?int $maxTtl = null): CacheTokenStore
    {
        return new CacheTokenStore($this->cache, 'tokens:edr', $maxTtl);
    }

    /**
     * Remaining lifetime of the cached entry, from the array store's own expiry bookkeeping.
     */
    private function ttl(): int
    {
        $store = $this->cache->getStore();
        self::assertInstanceOf(ArrayStore::class, $store);

        $entries = $store->all();
        self::assertArrayHasKey('tokens:edr', $entries, 'Expected a cached token entry.');

        return (int) round($entries['tokens:edr']['expiresAt'] - microtime(true));
    }
}
