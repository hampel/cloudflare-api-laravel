<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api\Laravel\Tests;

use Hampel\Cloudflare\Api\Entity\DnsRecord;
use Hampel\Cloudflare\Api\Entity\Zone;
use Hampel\Cloudflare\Api\Laravel\CloudflareManager;
use Hampel\Cloudflare\Api\Laravel\Facades\Cloudflare;
use Hampel\Cloudflare\Api\Laravel\Tests\Fixture\ZoneSettings;
use Hampel\Cloudflare\Api\Result\TokenVerification;
use Illuminate\Http\Client\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

/**
 * The README's examples, run.
 *
 * A pasted snippet gets hand-edited when the code around it changes and quietly stops matching
 * what the package does. These are the ones with a signature in them, so a renamed accessor or a
 * changed return type fails here rather than being discovered by somebody following the
 * documentation.
 *
 * The core package's own history is why this file is not optional: three defects were found in it
 * after it was written, and the one nothing local could have caught was found by installing the
 * published package and running the README's own example.
 */
final class ReadmeExamplesTest extends TestCase
{
    #[Test]
    public function the_usage_example_finds_a_zone_and_writes_a_record(): void
    {
        Http::fake([
            'api.cloudflare.com/client/v4/zones?*' => Http::response(self::collection([self::zone()])),
            'api.cloudflare.com/client/v4/zones/*/dns_records*' => Http::response(self::envelope(self::record())),
        ]);

        $zone = Cloudflare::zones()->getByName('example.com');

        $this->assertInstanceOf(Zone::class, $zone);
        $this->assertSame(self::ZONE_ID, $zone->id);

        $record = Cloudflare::zones()->records($zone->id)->create(
            DnsRecord::a('www', '203.0.113.10')->withTtl(300)
        );

        $this->assertInstanceOf(DnsRecord::class, $record);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://api.cloudflare.com/client/v4/zones/' . self::ZONE_ID . '/dns_records'
            && $request['type'] === 'A'
            && $request['content'] === '203.0.113.10'
            && $request['ttl'] === 300);
    }

    #[Test]
    public function listing_a_zones_records_reaches_the_bound_endpoint(): void
    {
        Http::fake([
            'api.cloudflare.com/*' => Http::response(self::collection([self::record()], 1, 100)),
        ]);

        $records = Cloudflare::zones()->records(self::ZONE_ID)->all();

        $this->assertCount(1, $records);
        $this->assertInstanceOf(DnsRecord::class, $records[0]);
    }

    #[Test]
    public function the_token_check_answers_what_the_readme_says_it_does(): void
    {
        Http::fake([
            'api.cloudflare.com/client/v4/user/tokens/verify' => Http::response(self::envelope([
                'id' => 'ed17574386854bf78a67040be0a770b0',
                'status' => 'active',
                'not_before' => '2026-01-01T00:00:00Z',
                'expires_on' => '2030-01-01T00:00:00Z',
            ])),
        ]);

        $token = Cloudflare::verify();

        $this->assertInstanceOf(TokenVerification::class, $token);
        $this->assertTrue($token->isActive());
        $this->assertFalse($token->expiresWithinDays(30));

        // The summary is what goes in a startup log, so it must not carry the token.
        $this->assertStringNotContainsString('token-under-test', $token->summary());
    }

    #[Test]
    public function a_named_account_is_reached_the_way_the_readme_says(): void
    {
        Http::fake(['api.cloudflare.com/*' => Http::response(self::collection([self::zone()]))]);

        $zones = Cloudflare::client('customers')->zones()->all();

        $this->assertCount(1, $zones);
        Http::assertSent(fn (Request $request): bool => $request->hasHeader(
            'Authorization',
            'Bearer customers-token'
        ));
    }

    #[Test]
    public function the_manager_can_be_injected_instead_of_using_the_facade(): void
    {
        Http::fake(['api.cloudflare.com/*' => Http::response(self::collection([self::zone()]))]);

        $cloudflare = $this->container()->make(CloudflareManager::class);

        $this->assertCount(1, $cloudflare->client('customers')->zones()->all());
    }

    #[Test]
    public function the_testing_section_snippet_works_with_the_fixture_it_shows(): void
    {
        // The README's testing example, byte for byte in its payload. A trimmed fixture is better
        // documentation than a full one, and only if it actually parses - the envelope plus two
        // keys is the minimum the core package needs to build a Zone.
        Http::preventStrayRequests();

        Http::fake([
            'api.cloudflare.com/*' => Http::response([
                'success' => true,
                'errors' => [],
                'messages' => [],
                'result' => [['id' => '023e105f4ecef8ad9ca31a8372d0c353', 'name' => 'example.com']],
                'result_info' => ['page' => 1, 'per_page' => 20, 'count' => 1, 'total_count' => 1],
            ]),
        ]);

        $zone = Cloudflare::zones()->findByName('example.com');

        $this->assertInstanceOf(Zone::class, $zone);
        $this->assertSame('023e105f4ecef8ad9ca31a8372d0c353', $zone->id);

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization'));
    }

    #[Test]
    public function an_endpoint_the_core_does_not_wrap_is_reachable_through_the_facade(): void
    {
        // The extension point, through the container. The core package wraps a dozen of
        // Cloudflare's two thousand paths, so most consumers will write one of these - and it has
        // to arrive over this package's transport, or every test of it escapes the fakes.
        Http::fake([
            'api.cloudflare.com/*' => Http::response(self::envelope([
                ['id' => 'ssl', 'value' => 'full'],
                ['id' => 'always_use_https', 'value' => 'on'],
            ])),
        ]);

        $settings = Cloudflare::client()->endpoint(ZoneSettings::class)->all(self::ZONE_ID);

        $this->assertCount(2, $settings);
        Http::assertSent(fn (Request $request): bool => $request->url()
            === 'https://api.cloudflare.com/client/v4/zones/' . self::ZONE_ID . '/settings');
    }

    #[Test]
    public function a_single_endpoint_is_reachable_through_the_connection(): void
    {
        Http::fake([
            'api.cloudflare.com/*' => Http::response(self::envelope(['id' => 'ssl', 'value' => 'full'])),
        ]);

        $ssl = Cloudflare::connection()->get('zones/' . self::ZONE_ID . '/settings/ssl')->object();

        $this->assertSame('full', $ssl['value'] ?? null);
    }

    #[Test]
    public function an_entity_handed_to_a_json_response_serialises_to_cloudflares_payload(): void
    {
        // Laravel's JSON response path honouring the entity's \JsonSerializable, not that the core
        // package serialises correctly - that is covered in the core package's own suite and
        // duplicating it would be this package testing that one.
        $payload = self::zone() + ['a_field_this_release_has_never_heard_of' => 'kept anyway'];

        Http::fake(['api.cloudflare.com/*' => Http::response(self::envelope($payload))]);

        $response = new JsonResponse(Cloudflare::zones()->get(self::ZONE_ID));
        $decoded = json_decode((string) $response->getContent(), true);

        $this->assertIsArray($decoded);
        $this->assertSame(self::ZONE_ID, $decoded['id'] ?? null);
        $this->assertSame('example.com', $decoded['name'] ?? null);
        $this->assertSame('kept anyway', $decoded['a_field_this_release_has_never_heard_of'] ?? null);
    }
}
