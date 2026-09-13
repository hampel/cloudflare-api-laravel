<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api\Laravel\Tests;

use Hampel\Cloudflare\Api\Entity\DnsRecord;
use Hampel\Cloudflare\Api\Laravel\CloudflareManager;
use Hampel\Cloudflare\Api\Laravel\CloudflareServiceProvider;
use Hampel\Cloudflare\Api\Laravel\Facades\Cloudflare;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class TransportTest extends TestCase
{
    #[Test]
    public function a_replacement_transport_is_used_by_every_account(): void
    {
        // The reason the transport is bound under a container key rather than constructed inside
        // the manager: an application with its own outbound HTTP policy - a proxy-aware,
        // SSRF-guarded client everything is required to go through - binds it under
        // cloudflare.http_client and this package uses it, instead of the application writing a
        // second API client.
        $body = (string) json_encode(self::envelope(['id' => self::ZONE_ID, 'name' => 'example.com']));

        $recorder = new class ($body) implements ClientInterface {
            /** @var list<string> */
            public array $sent = [];

            /** @var list<string> */
            public array $credentials = [];

            public function __construct(private readonly string $body)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->sent[] = (string) $request->getUri();
                $this->credentials[] = $request->getHeaderLine('Authorization');

                return new \GuzzleHttp\Psr7\Response(
                    200,
                    ['Content-Type' => 'application/json'],
                    $this->body
                );
            }
        };

        $this->container()->instance(CloudflareServiceProvider::HTTP_CLIENT, $recorder);

        Cloudflare::zones()->get(self::ZONE_ID);
        Cloudflare::client('customers')->zones()->get(self::ZONE_ID);

        $this->assertSame([
            'https://api.cloudflare.com/client/v4/zones/' . self::ZONE_ID,
            'https://api.cloudflare.com/client/v4/zones/' . self::ZONE_ID,
        ], $recorder->sent);

        // Same transport, different credential - which is the property that makes a manager
        // worth having rather than two containers' worth of wiring.
        $this->assertSame([
            'Bearer token-under-test',
            'Bearer customers-token',
        ], $recorder->credentials);
    }

    #[Test]
    public function global_request_middleware_reaches_this_packages_requests(): void
    {
        // Rebuilding the pending request per send is what buys this: an application's own Http::
        // configuration applies to the package's traffic without the package knowing anything
        // about it.
        Http::globalRequestMiddleware(fn (RequestInterface $request): RequestInterface => $request->withHeader('X-Application', 'under-test'));

        Http::fake(['api.cloudflare.com/*' => Http::response(self::envelope(self::zone()))]);

        Cloudflare::zones()->get(self::ZONE_ID);

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-Application', 'under-test'));
    }

    #[Test]
    public function the_configured_base_uri_is_where_the_requests_go(): void
    {
        // The setting exists for a recorded fixture served locally and for a proxy that
        // terminates the connection. Asserted through the transport rather than only on the
        // Config object, because a base URI that reached Config and not the wire would look
        // configured and change nothing.
        $this->container()->make(Config::class)->set('cloudflare.base_uri', 'http://localhost:8080');

        Http::fake(['localhost:8080/*' => Http::response(self::envelope(self::zone()))]);

        Cloudflare::zones()->get(self::ZONE_ID);

        Http::assertSent(fn (Request $request): bool => $request->url()
            === 'http://localhost:8080/client/v4/zones/' . self::ZONE_ID);
    }

    #[Test]
    public function the_configured_page_size_is_asked_for_on_every_list(): void
    {
        $this->container()->make(Config::class)->set('cloudflare.page_size', 50);

        Http::fake(['api.cloudflare.com/*' => Http::response(self::collection([self::zone()], 1, 50))]);

        Cloudflare::zones()->list();

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'per_page=50'));
    }

    #[Test]
    public function a_global_page_size_the_zones_endpoint_refuses_fails_before_a_request_is_spent(): void
    {
        // THE TRAP IN THIS SETTING, and the reason the shipped config leaves it null. The
        // limits are per ENDPOINT on this API: DNS records take 1 to 5000000 and zones take 5
        // to 50. So 100 - the obvious number, and the one DNS records already defaults to -
        // is refused by the zones endpoint, and it is refused the first time something lists
        // zones rather than when the application boots.
        //
        // Nothing here can make that safe, because one number cannot satisfy both. What it can
        // do is cost nothing: the refusal happens before the request is sent, so a wrong value
        // is a failed call and never a wasted one against a five-minute rate limit.
        $this->container()->make(Config::class)->set('cloudflare.page_size', 100);

        Http::preventStrayRequests();

        // Records accept it.
        Http::fake(['api.cloudflare.com/*' => Http::response(self::collection([self::record()], 1, 100))]);
        $this->assertCount(1, Cloudflare::records()->all(self::ZONE_ID));

        // Zones do not, and say which endpoint and which limits.
        $this->expectException(\Hampel\Cloudflare\Api\Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('between 5 and 50 for zones');

        Cloudflare::zones()->list();
    }

    #[Test]
    public function the_configured_timeouts_reach_the_request(): void
    {
        // Not automatic. PendingRequest merges its options only inside its own sendRequest(), and
        // the adapter sends through the built Guzzle client instead, so the timeouts set on the
        // pending request never reach a request unless they are passed on by hand. Read at the
        // handler, which is where Guzzle acts on them.
        $this->container()->make(Config::class)->set('cloudflare.timeout', 7);
        $this->container()->make(Config::class)->set('cloudflare.connect_timeout', 3);
        $this->container()->forgetInstance(CloudflareServiceProvider::HTTP_CLIENT);
        $this->container()->forgetInstance(CloudflareManager::class);

        $options = $this->optionsSeenByTheHandler();

        Http::fake(['api.cloudflare.com/*' => Http::response(self::envelope(self::zone()))]);

        Cloudflare::zones()->get(self::ZONE_ID);

        $this->assertSame(7.0, $options[0]['timeout'] ?? null);
        $this->assertSame(3.0, $options[0]['connect_timeout'] ?? null);
    }

    #[Test]
    public function a_global_transport_option_reaches_the_request(): void
    {
        // Http::globalOptions() is how an application sets a proxy or a CA bundle for all of its
        // outbound traffic, and the README says it applies here.
        Http::globalOptions([
            'verify' => '/etc/ssl/certs/corporate-ca.pem',
            'proxy' => ['https' => 'http://proxy.example.test:3128', 'no' => ['localhost']],
        ]);

        $options = $this->optionsSeenByTheHandler();

        Http::fake(['api.cloudflare.com/*' => Http::response(self::envelope(self::zone()))]);

        Cloudflare::zones()->get(self::ZONE_ID);

        $this->assertSame('/etc/ssl/certs/corporate-ca.pem', $options[0]['verify'] ?? null);
        $this->assertSame(['https' => 'http://proxy.example.test:3128', 'no' => ['localhost']], $options[0]['proxy'] ?? null);
    }

    #[Test]
    public function a_global_option_that_would_change_the_request_is_not_applied(): void
    {
        // The core package builds the request; the transport sends it as built. A global header,
        // query or body would otherwise overwrite its Accept and Authorization headers and
        // replace its query string or body - on this package, rewriting a DNS record's content.
        Http::globalOptions([
            'headers' => ['Accept' => 'text/html', 'Authorization' => 'Bearer not-this-one'],
            'query' => ['injected' => '1'],
            'json' => ['injected' => true],
        ]);

        Http::fake(['api.cloudflare.com/*' => Http::response(self::envelope(self::record()))]);

        Cloudflare::records()->create(self::ZONE_ID, DnsRecord::a('www', '203.0.113.10'));

        Http::assertSent(fn (Request $request): bool => $request->url()
                === 'https://api.cloudflare.com/client/v4/zones/' . self::ZONE_ID . '/dns_records'
            && $request->hasHeader('Accept', 'application/json')
            && $request->hasHeader('Authorization', 'Bearer token-under-test')
            && $request['type'] === 'A'
            && $request['content'] === '203.0.113.10'
            && ! isset($request['injected']));
    }

    /**
     * Records the Guzzle options of every request, as the handler receives them.
     *
     * @return \ArrayObject<int, array<array-key, mixed>>
     */
    private function optionsSeenByTheHandler(): \ArrayObject
    {
        /** @var \ArrayObject<int, array<array-key, mixed>> $seen */
        $seen = new \ArrayObject();

        Http::globalMiddleware(static fn (callable $handler): callable => static function (RequestInterface $request, array $options) use ($handler, $seen): mixed {
            $seen[] = $options;

            return $handler($request, $options);
        });

        return $seen;
    }
}
