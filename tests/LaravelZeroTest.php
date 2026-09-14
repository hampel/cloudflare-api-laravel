<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api\Laravel\Tests;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
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
use Psr\Http\Message\RequestInterface;

/**
 * The package on a Laravel Zero application, which is not the same container as a Laravel one.
 *
 * Laravel binds Illuminate\Http\Client\Factory as a singleton in FoundationServiceProvider.
 * Laravel Zero does not register that provider - its own set is Build, Cache, Collision,
 * CommandRecorder, Composer, Filesystem, GitVersion and NullLogger - so unless this package binds
 * one, the container builds a fresh Factory on every make() and the adapter, which resolves the
 * factory per send, gets a different instance from the one the Http facade configures.
 *
 * Faking survives that, because Http::fake() and preventStrayRequests() swap() their instance
 * into the container. What does not survive is configuration made through the facade without a
 * swap - Http::globalOptions(), Http::globalMiddleware() - which would be silently dropped from
 * this package's requests. Testbench boots a full application, so nothing in the rest of the
 * suite can see it.
 *
 * Laravel Zero is also a likely home for this package. A tool that reconciles DNS from a file, or
 * updates a record for a dynamic address, or renews a certificate, is a command rather than a web
 * request.
 */
final class LaravelZeroTest extends \PHPUnit\Framework\TestCase
{
    private const ZONE_ID = '023e105f4ecef8ad9ca31a8372d0c353';

    #[Test]
    public function a_global_option_set_through_the_facade_reaches_the_request(): void
    {
        // The behaviour the provider's factory singleton protects. Http::globalOptions() and
        // Http::globalMiddleware() configure the facade's own resolved instance and never touch
        // the container. Unbound, the adapter's per-send make() builds a different factory, so a
        // proxy or CA bundle an application set globally is silently dropped from this package's
        // requests. (Http::fake() and preventStrayRequests() are NOT the risk here: both swap()
        // their instance into the container, so the adapter finds them with or without the
        // singleton - which is what the test this replaces used to assert, vacuously.)
        //
        // Fully offline: the middleware answers the request itself, so nothing reaches a handler
        // that could open a connection. And contained regardless: base_uri is a reserved .invalid
        // host, so if the middleware is lost the request fails to resolve rather than reaching
        // Cloudflare with the test token.
        $app = $this->laravelZeroApplication();
        $app->make('config')->set('cloudflare.base_uri', 'https://api.cloudflare.invalid');

        $client = $app->make(CloudflareManager::class)->client('main');

        /** @var \ArrayObject<int, mixed> $verify */
        $verify = new \ArrayObject();

        try {
            Http::globalOptions(['verify' => '/etc/ssl/certs/corporate-ca.pem']);
            Http::globalMiddleware(static fn (callable $handler): callable => static function (RequestInterface $request, array $options) use ($verify): PromiseInterface {
                $verify[] = $options['verify'] ?? null;

                return Create::promiseFor(new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
                    'success' => true,
                    'errors' => [],
                    'messages' => [],
                    'result' => ['id' => self::ZONE_ID, 'name' => 'example.com', 'status' => 'active'],
                ])));
            });

            $this->assertSame('example.com', $client->zones()->get(self::ZONE_ID)->name);
            $this->assertSame(['/etc/ssl/certs/corporate-ca.pem'], $verify->getArrayCopy());
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
