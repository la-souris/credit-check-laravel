<?php

declare(strict_types=1);

namespace LaSouris\CreditCheck\Laravel\Tests;

use GuzzleHttp\Client as GuzzleClient;
use LaSouris\CreditCheck\Laravel\CreditCheckerServiceProvider;
use Nyholm\Psr7\Factory\Psr17Factory;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [CreditCheckerServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // Bind PSR-18/17 services so the EDR driver can be constructed.
        $app->singleton(ClientInterface::class, static fn (): ClientInterface => new GuzzleClient());
        $app->singleton(Psr17Factory::class, static fn (): Psr17Factory => new Psr17Factory());
        $app->bind(RequestFactoryInterface::class, Psr17Factory::class);
        $app->bind(StreamFactoryInterface::class, Psr17Factory::class);
    }
}
