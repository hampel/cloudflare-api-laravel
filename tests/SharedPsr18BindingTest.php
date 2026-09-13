<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api\Laravel\Tests;

use Hampel\Cloudflare\Api\Laravel\CloudflareServiceProvider;
use Hampel\Cloudflare\Api\Laravel\Facades\Cloudflare;
use Hampel\Cloudflare\Api\Laravel\Tests\Fixture\FakedSiblingProvider;
use Hampel\Cloudflare\Api\Laravel\Tests\Fixture\ForeignPsr18Provider;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;

/**
 * This package and any other that binds the shared PSR-18 key leave each other alone.
 *
 * Psr\Http\Client\ClientInterface is one key in the container, and singleton() on a bound key
 * replaces it. Every Laravel API wrapper used to bind it and build its manager from it, so in an
 * application with several installed, the last provider registered supplied the transport for all
 * of them - its timeouts, its defects - and a fix to one package's adapter reached an application
 * only when that package happened to register last. Measured in an application with three
 * wrappers installed. No single-package suite could see it, which is why the other package here
 * is a stub provider rather than a sibling.
 */
final class SharedPsr18BindingTest extends TestCase
{
    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        // Registered FIRST, so this package's provider comes after it.
        return [ForeignPsr18Provider::class, CloudflareServiceProvider::class];
    }

    #[Test]
    public function a_provider_registered_before_this_one_keeps_its_own_binding(): void
    {
        // What the fix changes in this order. The old provider "won" here by overwriting the
        // shared key with its own singleton - which broke whichever package or application had
        // bound it. So asserting only that this package uses its own transport would pass against
        // the old code too; the other binding surviving is what pins the fix.
        $this->assertSame(ForeignPsr18Provider::$client, $this->container()->make(ClientInterface::class));
    }

    #[Test]
    public function a_provider_registered_before_this_one_does_not_supply_its_transport(): void
    {
        $this->assertOwnTransportIsUsed();
    }

    #[Test]
    public function a_provider_registered_after_this_one_does_not_supply_its_transport(): void
    {
        // The order the old binding lost: another package's provider replacing the shared key
        // after this one had bound it.
        //
        // force: the class is already registered above, and register() would otherwise hand back
        // that instance without running its register() again - and the test would pass vacuously.
        $this->container()->register(new ForeignPsr18Provider($this->container()), force: true);

        $this->assertOwnTransportIsUsed();
    }

    #[Test]
    public function a_sibling_adapter_that_is_also_faked_does_not_lend_its_timeout(): void
    {
        // The realistic shape. A stub that answers by itself is caught only because the request
        // misses Http::fake(); a real sibling's adapter resolves the same faked factory, so the
        // fake intercepts whichever adapter sends and the response looks right either way. The
        // timeout is the only difference, and it is what the collision cost in production: one
        // package's calls bounded by another package's setting.
        $this->container()->register(new FakedSiblingProvider($this->container()));

        $this->assertOwnTransportIsUsed();
    }

    private function assertOwnTransportIsUsed(): void
    {
        $this->container()->make('config')->set('cloudflare.timeout', 4);

        // An ArrayObject rather than a reference: the outer arrow function captures by value, so a
        // reference taken inside it would point at a copy and record nothing.
        /** @var \ArrayObject<int, mixed> $timeouts */
        $timeouts = new \ArrayObject();

        Http::globalMiddleware(static fn (callable $handler): callable => static function (RequestInterface $request, array $options) use ($handler, $timeouts): mixed {
            $timeouts[] = $options['timeout'] ?? null;

            return $handler($request, $options);
        });

        Http::fake(['api.cloudflare.com/*' => Http::response(self::envelope(self::zone()))]);

        $this->assertSame('example.com', Cloudflare::zones()->get(self::ZONE_ID)->name);

        $this->assertSame([], ForeignPsr18Provider::$client->sent ?? []);
        $this->assertSame([4.0], $timeouts->getArrayCopy());
    }

    protected function tearDown(): void
    {
        ForeignPsr18Provider::$client = null;

        parent::tearDown();
    }
}
