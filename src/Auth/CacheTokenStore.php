<?php

declare(strict_types=1);

namespace LaSouris\CreditCheck\Laravel\Auth;

use Illuminate\Contracts\Cache\Repository;
use LaSouris\CreditCheck\Edr\Auth\AccessToken;
use LaSouris\CreditCheck\Edr\Auth\TokenStore;
use Throwable;

/**
 * Keeps the JWT and its refresh token in the Laravel cache, so every request and queue
 * worker reuses the same token instead of logging in again.
 *
 * Both tokens live in one entry: they are obtained and rotated together, and splitting them
 * would allow a half-written pair (a JWT without the refresh token that goes with it).
 *
 * The entry's TTL is derived from the token itself — it survives until the refresh token
 * is unusable, so an expired JWT can still be refreshed rather than re-logged-in — and is
 * capped by the configured `ttl` when one is set.
 */
final class CacheTokenStore implements TokenStore
{
    public function __construct(
        private readonly Repository $cache,
        private readonly string $key,
        private readonly ?int $maxTtl = null,
    ) {
    }

    public function get(): ?AccessToken
    {
        try {
            return AccessToken::fromArray($this->cache->get($this->key));
        } catch (Throwable) {
            // A broken cache must not take the credit check down: log in again instead.
            return null;
        }
    }

    public function put(AccessToken $token): void
    {
        $ttl = $this->ttlFor($token);

        // Nothing left to keep — drop whatever is stored instead of leaving the older,
        // now-contradicted entry in place.
        if ($ttl <= 0) {
            $this->forget();

            return;
        }

        try {
            $this->cache->put($this->key, $token->toArray(), $ttl);
        } catch (Throwable) {
            // Caching is an optimisation; failing to store only costs an extra login.
        }
    }

    public function forget(): void
    {
        try {
            $this->cache->forget($this->key);
        } catch (Throwable) {
            // Nothing to do — the caller re-authenticates either way.
        }
    }

    /**
     * Seconds the entry should stay cached: as long as the pair is of any use.
     */
    private function ttlFor(AccessToken $token): int
    {
        $now = time();
        $ttl = $token->expiresAt - $now;

        if ($token->refreshToken !== null && $token->refreshTokenExpiresAt !== null) {
            $ttl = max($ttl, $token->refreshTokenExpiresAt - $now);
        }

        if ($this->maxTtl !== null) {
            $ttl = min($ttl, $this->maxTtl);
        }

        return $ttl;
    }
}
