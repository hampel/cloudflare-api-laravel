<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api\Laravel\Tests;

use Hampel\Cloudflare\Api\Laravel\Facades\Cloudflare;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

/**
 * Which of Laravel's HTTP client events reach a listener, measured rather than asserted in prose.
 *
 * The split is not obvious and the obvious guess is wrong in one direction. `RequestSending` is
 * registered by `PendingRequest::__construct()` as a before-sending callback, and
 * `buildBeforeSendingHandler()` puts those callbacks INSIDE the handler stack - which this
 * package's adapter drives through `buildClient()->send()`. So it fires.
 * `ResponseReceived` and `ConnectionFailed` are dispatched from `PendingRequest::send()` itself,
 * a layer above the stack, which the adapter never calls. So they do not.
 *
 * The practical consequence is unchanged either way - Telescope's HTTP watcher listens for
 * `ResponseReceived`, so it still shows nothing - but a listener that counts requests on
 * `RequestSending` and pairs them with responses will count requests that never get one.
 */
final class EventTest extends TestCase
{
    #[Test]
    public function request_sending_fires_and_response_received_does_not(): void
    {
        Event::fake([RequestSending::class, ResponseReceived::class, ConnectionFailed::class]);

        Http::fake([
            'api.cloudflare.com/*' => Http::response(self::envelope(self::zone())),
        ]);

        Cloudflare::zones()->get(self::ZONE_ID);

        Event::assertDispatchedTimes(RequestSending::class, 1);
        Event::assertNotDispatched(ResponseReceived::class);
        Event::assertNotDispatched(ConnectionFailed::class);
    }

    #[Test]
    public function the_dispatched_event_carries_the_request_the_core_package_built(): void
    {
        // Worth pinning alongside the count: a listener reading the event gets the real request,
        // credential and all, rather than a placeholder - so anything filtering on URL or header
        // in an application's own listener works on this package's traffic.
        Event::fake([RequestSending::class]);

        Http::fake([
            'api.cloudflare.com/*' => Http::response(self::envelope(self::zone())),
        ]);

        Cloudflare::zones()->get(self::ZONE_ID);

        Event::assertDispatched(
            RequestSending::class,
            fn (RequestSending $event): bool => $event->request->url()
                === 'https://api.cloudflare.com/client/v4/zones/' . self::ZONE_ID
                && $event->request->hasHeader('Authorization', 'Bearer token-under-test')
        );
    }
}
