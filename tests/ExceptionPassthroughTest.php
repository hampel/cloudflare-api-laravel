<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api\Laravel\Tests;

use Hampel\Cloudflare\Api\Exception\ApiException;
use Hampel\Cloudflare\Api\Exception\ClientException;
use Hampel\Cloudflare\Api\Exception\ConflictException;
use Hampel\Cloudflare\Api\Exception\MalformedResponseException;
use Hampel\Cloudflare\Api\Exception\NotAuthenticatedException;
use Hampel\Cloudflare\Api\Exception\NotFoundException;
use Hampel\Cloudflare\Api\Exception\NotPermittedException;
use Hampel\Cloudflare\Api\Exception\ServerException;
use Hampel\Cloudflare\Api\Exception\TooManyRequestsException;
use Hampel\Cloudflare\Api\Exception\ValidationException;
use Hampel\Cloudflare\Api\Laravel\Facades\Cloudflare;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The status-to-exception mapping survives the trip through Laravel's HTTP client.
 *
 * This is the property to protect above ergonomics. What the core package is worth is not its
 * transport but its taxonomy: a client that reports every unsuccessful status the same way
 * cannot tell a zone that does not exist from a token that may not see it - both are an empty
 * result - and only one of them is a configuration error that should fail loudly.
 *
 * Laravel's own Http:: is the thing that flattens them, which is why an application reaching for
 * this package must not get that flattening back by the side door. Each case below would be an
 * indistinguishable "unsuccessful response" through the facade.
 */
final class ExceptionPassthroughTest extends TestCase
{
    /**
     * @return array<string, array{int, int, class-string<\Throwable>}>
     */
    public static function statuses(): array
    {
        return [
            'a rejected value, with the field on it' => [400, 9005, ValidationException::class],
            'an unusable token is not an empty result' => [401, 1000, NotAuthenticatedException::class],
            'the credential cannot reach this resource' => [403, 10000, NotPermittedException::class],
            'a missing record' => [404, 81044, NotFoundException::class],
            'a record that already exists' => [409, 81058, ConflictException::class],
            'rate limiting is typed so a caller can retry' => [429, 971, TooManyRequestsException::class],
            'Cloudflare broke, not the caller' => [500, 0, ServerException::class],
            'anything else the caller got wrong' => [418, 1, ClientException::class],
        ];
    }

    /**
     * @param  class-string<\Throwable>  $expected
     */
    #[Test]
    #[DataProvider('statuses')]
    public function a_status_arrives_as_its_own_exception(int $status, int $code, string $expected): void
    {
        Http::fake([
            'api.cloudflare.com/*' => Http::response(self::failure($code, 'Something Cloudflare said.'), $status),
        ]);

        $this->expectException($expected);

        Cloudflare::records()->get(self::ZONE_ID, self::RECORD_ID);
    }

    #[Test]
    public function a_token_cloudflare_cannot_parse_is_a_credential_failure_despite_its_400(): void
    {
        // The bug that 0.1.1 of the core package fixes, and the one worth a test at this layer
        // too, because it was found by INSTALLING the published package and running the README's
        // own example - not by any of the 156 tests, the level 10 analysis or the live harness,
        // all of which built the client with a plausible token.
        //
        // A token can be refused two ways and only one of them is a 401. A forty-character value
        // of the right shape but the wrong content answers 401 with code 1000. A value that does
        // not look like a token at all - a placeholder left in a config file, a truncated copy -
        // is rejected before authentication runs and answers 400 with code 6003, "Invalid request
        // headers". Both are the credential; a caller catching NotAuthenticatedException around
        // its startup check must see both, or the commonest configuration mistake escapes the
        // catch that exists for it.
        Http::fake([
            'api.cloudflare.com/*' => Http::response(
                self::failure(ApiException::CODE_INVALID_REQUEST_HEADERS, 'Invalid request headers'),
                400
            ),
        ]);

        $this->expectException(NotAuthenticatedException::class);

        Cloudflare::verify();
    }

    #[Test]
    public function a_missing_zone_is_a_403_and_find_treats_it_as_an_ordinary_answer(): void
    {
        // Cloudflare answers an unknown zone id with 403 and code 9109, not 404 - deliberately,
        // since a 404 would confirm to a credential which zone ids exist. So "no such zone" and
        // "not your zone" are genuinely the same reply, and find() absorbs it.
        Http::fake([
            'api.cloudflare.com/*' => Http::response(self::failure(9109, 'Invalid zone identifier'), 403),
        ]);

        $this->assertNull(Cloudflare::zones()->find(self::ZONE_ID));
    }

    #[Test]
    public function a_403_for_any_other_reason_still_raises(): void
    {
        // The other half, and the one that earns the discrimination its keep. A token whose
        // permissions are wrong reports 403 with code 10000, "Authentication error". Silently
        // returning null for that would turn a misconfigured credential into "the zone does not
        // exist", which sends whoever reads it to the wrong dashboard.
        //
        // The distinction rides on the error code AND message in the body, so a transport that
        // swallowed or reshaped the body would collapse them into one.
        Http::fake([
            'api.cloudflare.com/*' => Http::response(self::failure(10000, 'Authentication error'), 403),
        ]);

        $this->expectException(NotPermittedException::class);

        Cloudflare::zones()->find(self::ZONE_ID);
    }

    #[Test]
    public function a_token_refused_by_its_ip_filter_raises_rather_than_reading_as_a_missing_zone(): void
    {
        // Cloudflare sends code 9109 for two unrelated things: an unknown zone id, and a token
        // used from outside its IP address filter. Only the MESSAGE tells them apart, so this is
        // the case where a transport keeping codes but reshaping or dropping messages would do
        // silent damage: a location refusal would come back as "no such zone" for a zone that
        // exists. Asserted through the adapter, with the message byte for byte as Cloudflare
        // sends it, rather than trusting the core package's own suite.
        //
        // Needs hampel/cloudflare-api 1.0.1; 1.0.0 read every 9109 as a missing zone.
        Http::fake([
            'api.cloudflare.com/*' => Http::response(
                self::failure(9109, 'Cannot use the access token from location: 203.0.113.99'),
                403
            ),
        ]);

        $this->expectException(NotAuthenticatedException::class);
        $this->expectExceptionMessage('Cannot use the access token from location: 203.0.113.99');

        Cloudflare::zones()->find(self::ZONE_ID);
    }

    #[Test]
    public function a_two_hundred_whose_body_reports_failure_is_still_a_failure(): void
    {
        // THE STATUS IS NOT THE WHOLE ANSWER on this API: the envelope carries its own `success`
        // flag, and a 2xx whose body says false is a real shape - historically on endpoints
        // reporting a partial failure, and on anything sitting in front of the API that answers
        // in Cloudflare's envelope. Trusting the status alone hands the caller a null `result`
        // dressed as an empty collection, which on a DNS client is the answer that gets acted on.
        Http::fake([
            'api.cloudflare.com/*' => Http::response(self::failure(1001, 'Request had no data.'), 200),
        ]);

        $this->expectException(ApiException::class);

        Cloudflare::records()->all(self::ZONE_ID);
    }

    #[Test]
    public function a_rejected_field_is_still_readable_on_the_exception(): void
    {
        // Cloudflare points at a field with a JSON pointer, which the core package flattens to a
        // path - so `/content` becomes `content` and a form has somewhere to put the message.
        Http::fake([
            'api.cloudflare.com/*' => Http::response([
                'success' => false,
                'errors' => [[
                    'code' => 9005,
                    'message' => 'Content for A record must be a valid IPv4 address.',
                    'error_chain' => [],
                    'source' => ['pointer' => '/content'],
                ]],
                'messages' => [],
                'result' => null,
            ], 400),
        ]);

        try {
            Cloudflare::records()->create(self::ZONE_ID, self::record());
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertTrue($e->hasCode(9005));
            $this->assertSame(['Content for A record must be a valid IPv4 address.'], $e->messages());
            $this->assertTrue($e->concerns('content'));
        }
    }

    #[Test]
    public function a_success_whose_body_is_not_json_is_somebody_elses_answer(): void
    {
        // A maintenance page, a WAF challenge, a CDN interstitial and a truncated body are all a
        // 200 with something other than JSON in it. Returned as an empty result they would read
        // as "this zone has no records" everywhere downstream - and code that acts on that answer
        // deletes things.
        Http::fake([
            'api.cloudflare.com/*' => Http::response('<html><body>Down for maintenance</body></html>', 200),
        ]);

        $this->expectException(MalformedResponseException::class);

        Cloudflare::records()->all(self::ZONE_ID);
    }

    #[Test]
    public function faking_with_no_arguments_is_loud_rather_than_an_empty_zone(): void
    {
        // Worth pinning because the sibling packages differ here, and the difference is in this
        // package's favour. Http::fake() with no arguments answers every request with an empty
        // 200. On an API whose every endpoint answers in an envelope, an empty body cannot be a
        // valid answer - so the core package raises rather than reading it as "this zone has no
        // records".
        //
        // A fake with a forgotten body is therefore a test failure here and not a silently wrong
        // one. Give it a body anyway; relying on this is relying on an absence.
        Http::fake();

        $this->expectException(MalformedResponseException::class);

        Cloudflare::records()->all(self::ZONE_ID);
    }
}
