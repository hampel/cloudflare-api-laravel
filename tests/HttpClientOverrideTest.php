<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api\Laravel\Tests;

use Hampel\Cloudflare\Api\Laravel\CloudflareServiceProvider;
use Hampel\Cloudflare\Api\Laravel\Facades\Cloudflare;
use Hampel\Cloudflare\Api\Laravel\Tests\Fixture\HttpClientOverrideProvider;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

/**
 * An application's override of cloudflare.http_client survives whichever order the providers run.
 *
 * A full Laravel application registers package-discovered providers before its own, so an
 * override made in the application's provider comes last and would win even against singleton().
 * Laravel Zero runs no discovery: the order is the config/app.php list, and the README's own
 * example lists AppServiceProvider first. The package binds its key with singletonIf() so that
 * order cannot silently discard the application's choice.
 */
final class HttpClientOverrideTest extends TestCase
{
    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        // The override is registered BEFORE the package - the order singleton() would lose.
        return [HttpClientOverrideProvider::class, CloudflareServiceProvider::class];
    }

    #[Test]
    public function an_override_registered_before_this_provider_is_the_transport_used(): void
    {
        // Faked and guarded, although the override never touches Laravel's HTTP client. If the
        // override is lost, the package's own adapter sends instead - and without a fake that is a
        // real request to Cloudflare with the suite's token, which is what the first draft of this
        // test did when probed against singleton(). With the fake, a regression answers a
        // different zone name and fails on the assertion below, off the network.
        Http::preventStrayRequests();
        Http::fake(['api.cloudflare.com/*' => Http::response(self::envelope(self::zone(null, 'package-adapter.example.com')))]);

        $zone = Cloudflare::zones()->get(self::ZONE_ID);

        $this->assertSame('override.example.com', $zone->name);
        $this->assertSame(
            ['https://api.cloudflare.com/client/v4/zones/' . self::ZONE_ID],
            HttpClientOverrideProvider::$client->sent ?? []
        );
    }

    protected function tearDown(): void
    {
        HttpClientOverrideProvider::$client = null;

        parent::tearDown();
    }
}
