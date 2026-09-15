<?php

declare(strict_types=1);

namespace LaSouris\CreditCheck\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use LaSouris\CreditCheck\Sdk\Provider\CreditChecker;

final class CreditCheckerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/credit-check.php', 'credit-check');

        $this->app->singleton(CreditCheckerManager::class, static function (Application $app): CreditCheckerManager {
            return new CreditCheckerManager($app);
        });

        $this->app->alias(CreditCheckerManager::class, 'credit-check');

        // Resolving the CreditChecker contract yields the default driver.
        $this->app->bind(CreditChecker::class, static function (Application $app): CreditChecker {
            return $app->make(CreditCheckerManager::class)->driver();
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/credit-check.php' => $this->app->configPath('credit-check.php'),
            ], 'credit-check-config');
        }
    }
}
