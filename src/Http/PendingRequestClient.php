<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api\Laravel\Http;

use GuzzleHttp\RequestOptions;
use GuzzleHttp\TransferStats;
use GuzzleHttp\Utils;
use Illuminate\Http\Client\Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-18 client that sends through Laravel's HTTP client.
 *
 * This is the whole reason the package exists. hampel/cloudflare-api takes a PSR-18 client of
 * its own, so by default nothing it sends is visible to `Http::fake()` - an application
 * testing against it has to fake at the transport library instead, in a vocabulary its other
 * tests do not use. The core package's Connection docblock says the PSR-18 seam "lets a
 * Laravel integration route this traffic through Http::fake()"; THIS CLASS IS THAT
 * INTEGRATION, and without it the sentence is not true of anything. A PSR-18 client is not
 * Laravel's HTTP client, and Http::fake() intercepts at Laravel's layer.
 *
 * Handing the core package this adapter puts every request it makes on the same handler stack
 * `Http::` builds, so:
 *
 *     Http::fake(['api.cloudflare.com/*' => Http::response(['success' => true, 'result' => [...]])]);
 *
 *     $zone = Cloudflare::zones()->getByName('example.com');   // the package's real code path
 *
 *     Http::assertSent(fn ($request) => $request->hasHeader('Authorization'));
 *
 * `Http::preventStrayRequests()` works too, and reports the escape as its own
 * StrayRequestException rather than as a transport failure - Connection::dispatch() catches
 * ClientExceptionInterface specifically, and Laravel's exception is a plain RuntimeException,
 * so it passes through with the URL still in the message.
 *
 * REBUILT PER REQUEST, DELIBERATELY. Factory::fake() REPLACES the factory's stub collection
 * rather than adding to it, and createPendingRequest() copies whatever is there at the moment
 * it is called. A pending request built once and kept therefore holds a snapshot: a fake
 * registered after the client was first resolved would never be consulted, and the request
 * would go to the real API - which on this package means a live change to somebody's DNS.
 * Rebuilding here means the stubs, the stray-request setting, `Http::globalOptions()` and
 * `Http::globalRequestMiddleware()` are all read at the moment of sending, so ordering stops
 * mattering.
 *
 * The Guzzle handler underneath is built once and reused, which is what stops that costing
 * anything: the handler owns curl's connection pool, so keep-alive survives between requests
 * even though the stack around it is new each time. Cloudflare pages DNS records at 100 by
 * default, so a zone with a few hundred records would otherwise pay a fresh TLS handshake per
 * page - and Cloudflare's rate limit is counted over five minutes, so a walk is exactly the
 * thing that wants its connection back.
 *
 * REDIRECTS ARE NOT FOLLOWED, and there is no setting for it. Guzzle's PSR-18 entry point
 * hard-codes `allow_redirects => false` (Client::sendRequest()), so the redirect middleware on
 * the stack never runs and a 3xx is handed back whole. That differs from `Http::get()`, which
 * does follow. No endpoint this package wraps answers a redirect, so it never arises in
 * practice - it is written down because the difference from `Http::` is the kind of thing
 * somebody tidies away.
 *
 * SENT WITH send() RATHER THAN sendRequest(), which needs explaining because sendRequest() is
 * the PSR-18 method and this class is a PSR-18 client.
 *
 * `laravel_data` and `on_stats` are PendingRequest's contract with the handler stack it
 * builds: PendingRequest::sendRequest() sets both on every call, and the recorder and stub
 * handlers assume they are there. Anything that drives that stack without going through that
 * method - as this class must, because the request it is given is already built by the core
 * package and has to reach Cloudflare byte for byte - has to supply them itself.
 *
 * Laravel 13 reads both defensively. Laravel 12 does not, and without them every request
 * raises "Undefined array key laravel_data" from the recorder and "Undefined array key
 * on_stats" from the stub - which an application with debug error handling turns into an
 * ErrorException, so it is a hard failure rather than a notice in a log.
 *
 * The three options beside them reproduce what Guzzle's own sendRequest() sets, so the PSR-18
 * contract is unchanged. http_errors in particular must stay off: with it on, a 404 would
 * arrive as a Guzzle exception and the core package would report it as a transport failure
 * instead of mapping it to NotFoundException - and on this API that mapping is load-bearing,
 * because a 403 with code 9109 means "no such zone" while a 403 with any other code means the
 * credential is wrong.
 *
 * WHAT IT DOES NOT DO: raise ResponseReceived or ConnectionFailed. Laravel dispatches those from
 * PendingRequest::send(), a layer above the handler stack, which this class never calls - so
 * anything listening for them, Telescope's HTTP client watcher included, will not show this
 * traffic. The core package logs every request through PSR-3 instead, which under Laravel
 * reaches the application log.
 *
 * RequestSending DOES fire, which is the half worth stating because the symmetry invites the
 * opposite guess. PendingRequest::__construct() registers it as a before-sending callback and
 * buildBeforeSendingHandler() puts those callbacks INSIDE the handler stack, which is exactly
 * what this class drives. So a listener that counts requests on RequestSending and pairs them
 * with responses will count requests that never get one.
 *
 * Measured on Laravel 13.31.0 and Laravel 12.61.1 - see tests/EventTest.php, which listens for
 * all three rather than restating this.
 */
final class PendingRequestClient implements ClientInterface
{
    /**
     * Guzzle's default handler, kept so curl can reuse connections. Created on first use
     * rather than in the constructor: a client that is resolved and never sent through - an
     * application with a configured account it does not touch on this request - should not
     * pay for one.
     *
     * @var callable|null
     */
    private $handler = null;

    public function __construct(
        private readonly Factory $factory,
        private readonly float $timeout,
        private readonly float $connectTimeout,
    ) {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->handler ??= Utils::chooseHandler();

        return $this->factory->createPendingRequest()
            ->timeout($this->timeout)
            ->connectTimeout($this->connectTimeout)
            ->setHandler($this->handler)
            ->buildClient()
            ->send($request, [
                RequestOptions::SYNCHRONOUS => true,
                RequestOptions::ALLOW_REDIRECTS => false,
                RequestOptions::HTTP_ERRORS => false,

                // Left empty rather than filled: Request::data() parses a JSON body out of the
                // request itself, so `$request['content']` works in an assertion without it.
                // The core package writes `application/json` with no charset parameter, which
                // Request::isJson() - a substring test - accepts.
                'laravel_data' => [],

                // Discarded. Laravel's own callback records TransferStats on the
                // PendingRequest, and this one is thrown away with the pending request that
                // built it.
                'on_stats' => static function (TransferStats $stats): void {
                },
            ]);
    }
}
