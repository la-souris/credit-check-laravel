<?php

declare(strict_types=1);

namespace LaSouris\CreditCheck\Laravel;

use Closure;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use LaSouris\CreditCheck\Edr\Auth\TokenStore;
use LaSouris\CreditCheck\Laravel\Auth\CacheTokenStore;
use LaSouris\CreditCheck\Edr\CreditCheck\EdrCreditChecker;
use LaSouris\CreditCheck\Edr\CreditCheck\EdrPayloadMapper;
use LaSouris\CreditCheck\Edr\Environment;
use LaSouris\CreditCheck\Edr\EdrClient;
use LaSouris\CreditCheck\Laravel\Support\EdrWebhook;
use LaSouris\CreditCheck\Sdk\Provider\CreditChecker;
use LaSouris\CreditCheck\Sdk\Provider\ProviderCapabilities;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Driver-based manager for the SDK's {@see CreditChecker} contract.
 *
 * Modelled on Laravel's Manager, but typed: every resolved driver implements
 * {@see CreditChecker}. Providers are configured as a list under `credit-check.providers`,
 * each naming a `class`; a provider's short name — used by driver('...') and the `default`
 * selector — comes from the #[Provider] attribute on that class, so it is read without
 * constructing the provider.
 *
 * @method \LaSouris\CreditCheck\Sdk\Response\Response<\LaSouris\CreditCheck\Sdk\Result\CreditCheckReceipt> submitCheck(\LaSouris\CreditCheck\Sdk\Request\CreateCreditCheckRequest $request)
 * @method \LaSouris\CreditCheck\Sdk\Response\Response<\LaSouris\CreditCheck\Sdk\Result\CreditCheckResult> getResult(string $reference)
 * @method \LaSouris\CreditCheck\Sdk\Response\Response<list<string>> getChangedChecksSince(\DateTimeInterface $since)
 */
class CreditCheckerManager
{
    /**
     * Per-class primary credential key. A provider counts as "configured" (and so eligible for
     * auto-selection as the default) when this key is set.
     *
     * @var array<class-string<CreditChecker>, string>
     */
    private const array PRIMARY_CREDENTIAL = [
        EdrCreditChecker::class => 'password',
    ];

    /** @var array<string, CreditChecker> */
    private array $drivers = [];

    /** @var array<string, Closure(Container, array<string, mixed>): CreditChecker> */
    private array $customCreators = [];

    public function __construct(
        private readonly Container $container,
    ) {
    }

    public function driver(?string $name = null): CreditChecker
    {
        $name ??= $this->getDefaultDriver();

        return $this->drivers[$name] ??= $this->resolve($name);
    }

    /**
     * Register a custom factory for a driver name (e.g. a third-party provider or a test double).
     *
     * @param Closure(Container, array<string, mixed>): CreditChecker $factory
     */
    public function extend(string $name, Closure $factory): self
    {
        $this->customCreators[$name] = $factory;

        return $this;
    }

    /**
     * Seed a ready-made provider instance under a name (mostly for tests).
     */
    public function set(string $name, CreditChecker $provider): self
    {
        $this->drivers[$name] = $provider;

        return $this;
    }

    /**
     * @return list<string> Names of providers whose primary credential is present.
     */
    public function configuredDrivers(): array
    {
        $names = [];

        foreach ($this->providerConfigs() as $entry) {
            if ($this->isConfigured($entry)) {
                $names[] = $this->driverName($entry['class']);
            }
        }

        return $names;
    }

    public function getDefaultDriver(): string
    {
        $configured = $this->config('credit-check.default');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $candidates = $this->configuredDrivers();
        if (count($candidates) === 1) {
            return $candidates[0];
        }

        throw new InvalidArgumentException(
            'No default credit-check driver configured; set credit-check.default or configure exactly one provider.',
        );
    }

    private function resolve(string $name): CreditChecker
    {
        if (isset($this->customCreators[$name])) {
            return $this->customCreators[$name]($this->container, $this->configFor($name));
        }

        $entry = $this->entryFor($name);
        $class = $entry['class'];
        $config = $entry['config'] ?? [];

        return match ($class) {
            EdrCreditChecker::class => $this->createEdrDriver($name, $config),
            default => $this->container->make($class, ['config' => $config]),
        };
    }

    /**
     * @param array<string, mixed> $config
     */
    private function createEdrDriver(string $name, array $config): EdrCreditChecker
    {
        $environment = match ((string) ($config['environment'] ?? 'uat')) {
            'production' => Environment::Production,
            default => Environment::Uat,
        };

        $email = (string) ($config['email'] ?? '');

        $client = EdrClient::create(
            $environment,
            $email,
            (string) ($config['password'] ?? ''),
            $this->container->make(ClientInterface::class),
            $this->container->make(RequestFactoryInterface::class),
            $this->container->make(StreamFactoryInterface::class),
            (string) ($config['api_version'] ?? '1.0'),
            $this->tokenStore($name, $config, $environment->value . '|' . $email),
        );

        return new EdrCreditChecker($client, new EdrPayloadMapper($this->webhookUrl()));
    }

    /**
     * The callback URL to send on order creation, or null to omit it entirely.
     *
     * `use_in_create` is bool|string: `false` (default) omits it, `true` derives it from this
     * application's own webhook route, and a string is sent as-is — for when the submitting
     * app's own APP_URL isn't the public address EDR should call (e.g. behind a gateway).
     */
    private function webhookUrl(): ?string
    {
        $setting = $this->config('credit-check.webhook.use_in_create', false);

        return match (true) {
            is_string($setting) && $setting !== '' => $setting,
            (bool) $setting => EdrWebhook::url($this->container->make('config')),
            default => null,
        };
    }

    /**
     * The token store a provider's HTTP client should use, or null when token caching is
     * switched off (then the provider keeps its token in memory for the request only).
     *
     * @param array<string, mixed> $config   The provider's own config.
     * @param string               $identity What the token belongs to (environment, account);
     *                                       hashed into the key so two accounts never share one.
     */
    private function tokenStore(string $name, array $config, string $identity): ?TokenStore
    {
        $cache = array_merge(
            (array) $this->config('credit-check.cache', []),
            (array) ($config['cache'] ?? []),
        );

        if (($cache['enabled'] ?? true) === false || !$this->container->bound(CacheFactory::class)) {
            return null;
        }

        $store = $cache['store'] ?? null;
        $prefix = is_string($cache['prefix'] ?? null) && $cache['prefix'] !== ''
            ? $cache['prefix']
            : 'credit-check:token';
        $ttl = isset($cache['ttl']) && is_numeric($cache['ttl']) ? (int) $cache['ttl'] : null;

        $repository = $this->container->make(CacheFactory::class)
            ->store(is_string($store) && $store !== '' ? $store : null);

        return new CacheTokenStore(
            $repository,
            sprintf('%s:%s:%s', $prefix, $name, substr(hash('sha256', $identity), 0, 32)),
            $ttl,
        );
    }

    /**
     * @return array{class: class-string<CreditChecker>, config?: array<string, mixed>}
     */
    private function entryFor(string $name): array
    {
        foreach ($this->providerConfigs() as $entry) {
            if ($this->driverName($entry['class']) === $name) {
                return $entry;
            }
        }

        throw new InvalidArgumentException(sprintf('Credit-check driver "%s" is not configured.', $name));
    }

    /**
     * @return array<string, mixed>
     */
    private function configFor(string $name): array
    {
        foreach ($this->providerConfigs() as $entry) {
            if ($this->driverName($entry['class']) === $name) {
                return $entry['config'] ?? [];
            }
        }

        return [];
    }

    /**
     * @param array{class: class-string<CreditChecker>, config?: array<string, mixed>} $entry
     */
    private function isConfigured(array $entry): bool
    {
        $key = self::PRIMARY_CREDENTIAL[$entry['class']] ?? null;
        $config = $entry['config'] ?? [];

        if ($key === null) {
            return $config !== [];
        }

        return isset($config[$key]) && $config[$key] !== '' && $config[$key] !== null;
    }

    /**
     * @param class-string<CreditChecker> $class
     */
    private function driverName(string $class): string
    {
        return ProviderCapabilities::of($class)->name;
    }

    /**
     * @return list<array{class: class-string<CreditChecker>, config?: array<string, mixed>}>
     */
    private function providerConfigs(): array
    {
        return array_values((array) $this->config('credit-check.providers', []));
    }

    private function config(string $key, mixed $default = null): mixed
    {
        return $this->container->make('config')->get($key, $default);
    }

    /**
     * Proxy any CreditChecker call to the default driver.
     *
     * @param array<int, mixed> $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->driver()->{$method}(...$arguments);
    }
}
