<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api\Laravel\Tests;

use Hampel\Cloudflare\Api\Authentication\ApiToken;
use Hampel\Cloudflare\Api\Entity\DnsRecord;
use Hampel\Cloudflare\Api\Entity\Zone;
use Hampel\Cloudflare\Api\Exception\NotFoundException;
use Hampel\Cloudflare\Api\Laravel\Facades\Cloudflare;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

/**
 * The reason the package exists.
 *
 * hampel/cloudflare-api takes its own PSR-18 client, so by default nothing it sends is visible
 * to Http::fake() and an application testing against it has to fake at the transport library
 * instead - in a vocabulary the rest of its suite does not use. Everything below goes through
 * the core package's real request building, filtering, status mapping and exception hierarchy;
 * only the socket is replaced.
 *
 * If this file does not pass, the package has no reason to exist.
 */
final class HttpFakeTest extends TestCase
{
    #[Test]
    public function a_faked_response_reaches_the_caller_as_a_typed_entity(): void
    {
        Http::fake([
            'api.cloudflare.com/*' => Http::response(self::collection([self::zone()])),
        ]);

        $zone = Cloudflare::zones()->findByName('example.com');

        $this->assertInstanceOf(Zone::class, $zone);
        $this->assertSame(self::ZONE_ID, $zone->id);
        $this->assertSame('example.com', $zone->name);
        $this->assertTrue($zone->isActive());
    }

    #[Test]
    public function the_request_is_recorded_for_assertion(): void
    {
        Http::fake([
            'api.cloudflare.com/*' => Http::response(self::envelope(self::zone())),
        ]);

        Cloudflare::zones()->get(self::ZONE_ID);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://api.cloudflare.com/client/v4/zones/' . self::ZONE_ID
            && $request->hasHeader('Authorization', 'Bearer token-under-test')
            && $request->hasHeader('Accept', 'application/json'));
    }

    #[Test]
    public function the_name_filter_is_visible_to_an_assertion(): void
    {
        // Worth its own test because findByName() is the call that turns the thing an
        // application has - a domain name - into the id every other endpoint wants, and its
        // whole correctness rests on Cloudflare honouring `?name=`. An application asserting
        // that it looked a zone up by name matches on the query string, and it only gets there
        // because the core package builds the request and this transport sends it unaltered.
        Http::fake([
            'api.cloudflare.com/*' => Http::response(self::collection([self::zone()])),
        ]);

        Cloudflare::zones()->findByName('EXAMPLE.com');

        // Lower-cased by the core package: DNS is case-insensitive and a zone is stored
        // lower-case, so the mixed-case argument above would otherwise find nothing.
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'name=example.com'));
    }

    #[Test]
    public function a_json_body_is_readable_by_the_assertion(): void
    {
        // Illuminate\Http\Client\Request::isJson() is a substring test over the Content-Type,
        // which the core package's bare `application/json` satisfies - so data() parses the
        // body and $request['content'] works. Assert on the parsed body rather than on the
        // encoded string: key order is not a promise anyone should depend on.
        Http::fake([
            'api.cloudflare.com/*' => Http::response(self::envelope(self::record())),
        ]);

        Cloudflare::records()->create(self::ZONE_ID, DnsRecord::a('www', '203.0.113.10')->withTtl(300));

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->isJson()
            && $request['type'] === 'A'
            && $request['content'] === '203.0.113.10'
            && $request['ttl'] === 300);
    }

    #[Test]
    public function a_patch_is_sent_as_a_patch_and_not_as_a_put(): void
    {
        // The distinction this API punishes hardest. Cloudflare's PUT is a FULL replacement -
        // a field left out is reset to its default, so a PUT carrying only `content` silently
        // clears the record's comment and tags, un-proxies it and returns its TTL to automatic,
        // with a 200 and no indication that anything else changed. patch() is the partial
        // update. A transport that rewrote the verb would be a data-loss bug, so it is asserted
        // here rather than assumed.
        Http::fake([
            'api.cloudflare.com/*' => Http::response(self::envelope(self::record())),
        ]);

        Cloudflare::records()->patch(self::ZONE_ID, self::RECORD_ID, ['content' => '203.0.113.11']);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
            && $request['content'] === '203.0.113.11'
            && ! isset($request['ttl']));
    }

    #[Test]
    public function faking_after_the_client_was_resolved_still_intercepts(): void
    {
        // The ordering a cached transport gets wrong. Factory::fake() REPLACES the factory's
        // stub collection, and createPendingRequest() copies whatever is there when it is
        // called - so a Guzzle client built at resolution time holds a snapshot taken before
        // these stubs existed, and the request would go to the real API. On a package that
        // edits DNS that is not a test failure, it is a live change to somebody's zone.
        // PendingRequestClient rebuilds per send, so it does not happen.
        $client = Cloudflare::client();

        Http::preventStrayRequests();
        Http::fake([
            'api.cloudflare.com/*' => Http::response(self::envelope(self::zone(null, 'late.example.com'))),
        ]);

        $this->assertSame('late.example.com', $client->zones()->get(self::ZONE_ID)->name);
    }

    #[Test]
    public function a_stray_request_is_reported_as_laravel_reports_it(): void
    {
        // Not disguised as the core package's RequestException. Connection::dispatch() catches
        // ClientExceptionInterface, and StrayRequestException is a plain RuntimeException, so it
        // arrives with Laravel's own message and the URL still in it.
        Http::preventStrayRequests();
        Http::fake(['example.test/*' => Http::response([])]);

        $this->expectException(StrayRequestException::class);
        $this->expectExceptionMessage('https://api.cloudflare.com/client/v4/zones/' . self::ZONE_ID);

        Cloudflare::zones()->get(self::ZONE_ID);
    }

    #[Test]
    public function an_error_status_still_maps_to_the_packages_exception_hierarchy(): void
    {
        // The layer supplies the transport and nothing else. A 404 has to arrive as
        // NotFoundException, not as an unsuccessful Response - telling a record that is not
        // there from a token that cannot see it is the whole reason the core package exists.
        Http::fake([
            'api.cloudflare.com/*' => Http::response(self::failure(81044, 'Record not found.'), 404),
        ]);

        $this->expectException(NotFoundException::class);

        Cloudflare::records()->get(self::ZONE_ID, self::RECORD_ID);
    }

    #[Test]
    public function the_rate_limit_headers_survive_the_trip(): void
    {
        // ResponseMeta is read off the response headers, so an integration that slows itself
        // down from `r` rather than waiting for a 429 depends on this transport handing the
        // headers back. A fake is the only place that can be asserted.
        //
        // The header shape is Cloudflare's own, measured on 2026-09-12: `r` is what is left, `t`
        // is seconds until the window resets - a DURATION, not a timestamp - and `q` on the
        // policy header is the quota. The policy is named after the ENDPOINT, so this budget
        // belongs to list_zones and says nothing about any other call.
        Http::fake([
            'api.cloudflare.com/*' => Http::response(self::envelope(self::zone()), 200, [
                'Ratelimit' => '"list_zones";r=1200;t=1',
                'Ratelimit-Policy' => '"list_zones";q=1201;w=300',
                'CF-Ray' => '8d2f1a9c4e7b0123-SYD',
            ]),
        ]);

        $response = Cloudflare::connection()->get('zones/' . self::ZONE_ID);

        $this->assertSame(1200, $response->meta->rateLimitRemaining);
        $this->assertSame(1, $response->meta->rateLimitResetsIn);
        $this->assertSame(1201, $response->meta->rateLimit);
        $this->assertSame('8d2f1a9c4e7b0123-SYD', $response->meta->ray);
    }

    #[Test]
    public function a_response_carrying_no_rate_limit_headers_is_not_read_as_exhaustion(): void
    {
        // The other half, and the reason every accessor on ResponseMeta is nullable. The
        // headers are sent PER ENDPOINT on this API - /zones carries them and
        // /user/tokens/verify does not - so a missing header means the question was not
        // answered. A client that read absence as "no requests left" would stop itself over an
        // endpoint that never reports a budget, or over a proxy that stripped them.
        Http::fake([
            'api.cloudflare.com/*' => Http::response(self::envelope(self::zone())),
        ]);

        $meta = Cloudflare::connection()->get('zones/' . self::ZONE_ID)->meta;

        $this->assertNull($meta->rateLimitRemaining);
        $this->assertNull($meta->rateLimit);
        $this->assertFalse($meta->isNearingRateLimit());
    }

    #[Test]
    public function a_derived_client_sends_through_the_same_faked_transport(): void
    {
        // withCredential() builds a new Client over the connection's existing transport. If it
        // did not, an application resolving a per-tenant token at request time would fake the
        // first call and reach the real API on every one after it. The core package's
        // Authentication interface exists for exactly that case.
        Http::fake([
            'api.cloudflare.com/*' => Http::response(self::envelope(self::zone(null, 'tenant.example.com'))),
        ]);

        $zone = Cloudflare::withCredential(new ApiToken('tenant-token'))->zones()->get(self::ZONE_ID);

        $this->assertSame('tenant.example.com', $zone->name);
        Http::assertSent(fn (Request $request): bool => $request->hasHeader(
            'Authorization',
            'Bearer tenant-token'
        ));
    }

    #[Test]
    public function a_walk_across_pages_goes_through_the_fake_on_every_page(): void
    {
        // Paging is where a cached pending request would show up as something other than a
        // clean failure: the first page intercepted and the second reaching the network. The
        // sequence also pins that the core package reads `total_pages` from result_info rather
        // than stopping at the first page.
        Http::fake([
            'api.cloudflare.com/*' => Http::sequence()
                ->push(self::collection([self::record('a1b2c3d4e5f60718293a4b5c6d7e8f90')], 1, 1, 2, 2))
                ->push(self::collection([self::record('b2c3d4e5f60718293a4b5c6d7e8f90a1')], 2, 1, 2, 2)),
        ]);

        $records = Cloudflare::records()->all(self::ZONE_ID);

        $this->assertCount(2, $records);
        Http::assertSentCount(2);
    }

    #[Test]
    public function the_bind_export_arrives_as_text_rather_than_as_a_malformed_response(): void
    {
        // The one endpoint here whose success is NOT the JSON envelope. Everywhere else a
        // non-JSON 200 is the maintenance page that MalformedResponseException exists to catch;
        // on this path it is the answer. Connection::raw() is what tells them apart, and it only
        // works if this transport hands the body back untouched.
        $zoneFile = "example.com.\t300\tIN\tA\t203.0.113.10\n";

        Http::fake([
            'api.cloudflare.com/*' => Http::response($zoneFile, 200, ['Content-Type' => 'text/plain']),
        ]);

        $this->assertSame($zoneFile, Cloudflare::records()->export(self::ZONE_ID));
    }
}
