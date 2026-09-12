<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api\Laravel\Tests;

use Hampel\Cloudflare\Api\Laravel\CloudflareManager;
use Hampel\Cloudflare\Api\Laravel\CloudflareServiceProvider;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Events\EventServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpClientFactory;
use Illuminate\Log\LogServiceProvider;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Monolog\Handler\NullHandler;
use PHPUnit\Framework\Attributes\Test;

/**
 * The package on a Laravel Zero application, which is not the same container as a Laravel one.
 *
 * Laravel binds Illuminate\Http\Client\Factory as a singleton in FoundationServiceProvider.
 * Laravel Zero does not register that provider - its own set is Build, Cache, Collision,
 * CommandRecorder, Composer, Filesystem, GitVersion and NullLogger - so unless this package binds
 * one, the container builds a fresh Factory on every make() and the instance this package holds
 * is not the instance the Http facade configures.
 *
 * The consequence is the failure the package exists to prevent, and it is silent: the fake does
 * not intercept and the request goes to the real API. On a DNS client that is a live change to
 * somebody's zone made by a test run. Testbench boots a full application, so nothing in the rest
 * of the suite can see it.
 *
 * Laravel Zero is also a likely home for this package. A tool that reconciles DNS from a file, or
 * updates a record for a dynamic address, or renews a certificate, is a command rather than a web
 * request.
 */
final class LaravelZeroTest extends \PHPUnit\Framework\TestCase
{
    private const ZONE_ID = '023e105f4ecef8ad9ca31a8372d0c353';

    #[Test]
    public function a_fake_registered_after_the_client_was_resolved_still_intercepts(): void
    {
        // The realistic ordering: a command resolves its client, and the test fakes after.
        // Without the singleton in the provider this reached the network.
        $app = $this->laravelZeroApplication();

        $client = $app->make(CloudflareManager::class)->client('main');

        Http::fake([
            'api.cloudflare.com/*' => Http::response([
                'success' => true,
                'errors' => [],
                'messages' => [],
                'result' => ['id' => self::ZONE_ID, 'name' => 'example.com', 'status' => 'active'],
            ]),
        ]);

        try {
            $this->assertSame('example.com', $client->zones()->get(self::ZONE_ID)->name);
        } finally {
            $this->tearDownFacades();
        }
    }

    #[Test]
    public function the_http_factory_is_bound_as_a_singleton_when_nothing_else_binds_one(): void
    {
        $app = $this->laravelZeroApplication();

        try {
            $this->assertTrue($app->bound(HttpClientFactory::class));
            $this->assertSame($app->make(HttpClientFactory::class), $app->make(HttpClientFactory::class));
            $this->assertSame($app->make(HttpClientFactory::class), Http::getFacadeRoot());
        } finally {
            $this->tearDownFacades();
        }
    }

    #[Test]
    public function an_application_that_already_binds_a_factory_keeps_its_own(): void
    {
        // singletonIf, not singleton: a full Laravel application registers
        // FoundationServiceProvider, and this package must not displace what it bound.
        $app = $this->laravelZeroApplication(function (Application $app): void {
            $app->instance(HttpClientFactory::class, new HttpClientFactory());
        });

        try {
            $existing = $app->make(HttpClientFactory::class);

            $this->assertSame($existing, $app->make(HttpClientFactory::class));
        } finally {
            $this->tearDownFacades();
        }
    }

    private function laravelZeroApplication(?callable $before = null): Application
    {
        $app = new Application(__DIR__ . '/..');
        $app->instance('config', new ConfigRepository());
        $app->register(new EventServiceProvider($app));
        $app->register(new LogServiceProvider($app));
        $app->make('config')->set('logging.default', 'null');
        $app->make('config')->set('logging.channels.null', [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ]);

        if ($before !== null) {
            $before($app);
        }

        (new CloudflareServiceProvider($app))->register();

        $app->make('config')->set('cloudflare.accounts.main', ['token' => 'token-under-test']);

        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);

        return $app;
    }

    private function tearDownFacades(): void
    {
        // A facade left pointing at a dead application fails a later test for a reason that has
        // nothing to do with it.
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
    }
}
