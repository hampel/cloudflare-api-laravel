<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api\Laravel\Http;

use Closure;
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
 * Rebuilding here means the stubs, the stray-request setting and `Http::globalRequestMiddleware()`
 * are all read at the moment of sending, so ordering stops mattering.
 *
 * ITS OPTIONS ARE PASSED ON BY HAND, AND ONLY THE TRANSPORT ONES. PendingRequest merges its
 * options - `->timeout()`, `->connectTimeout()`, `Http::globalOptions()` - only inside its own
 * sendRequest(), and this class calls send() on the built Guzzle client instead, so without
 * transportOptions() none of them reaches a request. Measured before that method existed: the
 * configured timeout and connect_timeout arrived at the handler as nothing, so a connection that
 * hangs waits on Guzzle's defaults rather than on the config. They are passed as an ALLOWLIST,
 * never wholesale: a global `headers` entry would overwrite the core package's Accept and
 * Authorization, and a global `query` or `json` would replace its query string or body - on this
 * package, a DNS record's content.
 *
 * THE FACTORY ITSELF IS RESOLVED PER REQUEST TOO, for the same reason one level up.
 * `Http::swap(new Factory)` - how a test starts from a clean set of fakes, since fake() merges -
 * binds a new factory into the container. A client holding the factory it was built with keeps
 * answering from the old one, past the new fakes and past the new factory's
 * preventStrayRequests(), so a test believing itself guarded sends a real request carrying the
 * configured token. The constructor takes a resolver for that reason, not an instance.
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
 * because the same 403 and code 9109 mean "no such zone" or "token refused from this address"
 * depending on the message alone, while a 403 with any other code means the credential is wrong.
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

    /**
     * @param  Closure(): Factory  $factory  resolves the factory at the moment of sending
     */
    public function __construct(
        private readonly Closure $factory,
        private readonly float $timeout,
        private readonly float $connectTimeout,
    ) {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        // Into a local: the handler's non-null narrowing does not survive the resolver call below.
        $handler = $this->handler ??= Utils::chooseHandler();

        $pending = ($this->factory)()->createPendingRequest()
            ->timeout($this->timeout)
            ->connectTimeout($this->connectTimeout)
            ->setHandler($handler);

        return $pending->buildClient()->send($request, [
            ...self::transportOptions($pending->getOptions()),

            RequestOptions::SYNCHRONOUS => true,
            RequestOptions::ALLOW_REDIRECTS => false,
            RequestOptions::HTTP_ERRORS => false,

            // Left empty rather than filled: Request::data() parses a JSON body out of the
            // request itself, so `$request['content']` works in an assertion without it. The
            // core package writes `application/json` with no charset parameter, which
            // Request::isJson() - a substring test - accepts.
            'laravel_data' => [],

            // Discarded. Laravel's own callback records TransferStats on the PendingRequest,
            // and this one is thrown away with the pending request that built it.
            'on_stats' => static function (TransferStats $stats): void {
            },
        ]);
    }

    /**
     * The options from a pending request that govern how a request is sent, never what is sent.
     *
     * Each is taken only when its value has the type Guzzle declares for it; an option that does
     * not is dropped rather than passed on to fail somewhere less obvious. Array forms are
     * rebuilt element by element for the same reason.
     *
     * @param  array<mixed>  $options
     * @return array{
     *     timeout?: int|float,
     *     connect_timeout?: int|float,
     *     read_timeout?: int|float,
     *     verify?: bool|string,
     *     version?: string|int|float,
     *     force_ip_resolve?: string,
     *     crypto_method?: int,
     *     crypto_method_max?: int,
     *     decode_content?: bool|string,
     *     cert?: string|array{0: string, 1?: string|null},
     *     cert_type?: string,
     *     ssl_key?: string|array{0: string, 1?: string|null},
     *     ssl_key_type?: string,
     *     proxy?: string|array{http?: string|null, https?: string|null, no?: string|array<array-key, string>|null},
     *     curl?: array<int|string, mixed>
     * }
     */
    private static function transportOptions(array $options): array
    {
        $transport = [];

        // One key at a time rather than a loop over names: an array built with a variable key
        // loses its shape to static analysis on the oldest PHPStan the package supports.
        if (self::isNumber($options['timeout'] ?? null)) {
            $transport['timeout'] = $options['timeout'];
        }

        if (self::isNumber($options['connect_timeout'] ?? null)) {
            $transport['connect_timeout'] = $options['connect_timeout'];
        }

        if (self::isNumber($options['read_timeout'] ?? null)) {
            $transport['read_timeout'] = $options['read_timeout'];
        }

        if (isset($options['verify']) && (is_bool($options['verify']) || is_string($options['verify']))) {
            $transport['verify'] = $options['verify'];
        }

        if (isset($options['version']) && (is_string($options['version']) || self::isNumber($options['version']))) {
            $transport['version'] = $options['version'];
        }

        if (isset($options['force_ip_resolve']) && is_string($options['force_ip_resolve'])) {
            $transport['force_ip_resolve'] = $options['force_ip_resolve'];
        }

        if (isset($options['crypto_method']) && is_int($options['crypto_method'])) {
            $transport['crypto_method'] = $options['crypto_method'];
        }

        if (isset($options['crypto_method_max']) && is_int($options['crypto_method_max'])) {
            $transport['crypto_method_max'] = $options['crypto_method_max'];
        }

        if (isset($options['decode_content']) && (is_bool($options['decode_content']) || is_string($options['decode_content']))) {
            $transport['decode_content'] = $options['decode_content'];
        }

        $cert = self::pathWithPassword($options['cert'] ?? null);

        if ($cert !== null) {
            $transport['cert'] = $cert;
        }

        if (isset($options['cert_type']) && is_string($options['cert_type'])) {
            $transport['cert_type'] = $options['cert_type'];
        }

        $sslKey = self::pathWithPassword($options['ssl_key'] ?? null);

        if ($sslKey !== null) {
            $transport['ssl_key'] = $sslKey;
        }

        if (isset($options['ssl_key_type']) && is_string($options['ssl_key_type'])) {
            $transport['ssl_key_type'] = $options['ssl_key_type'];
        }

        $proxy = self::proxy($options['proxy'] ?? null);

        if ($proxy !== null) {
            $transport['proxy'] = $proxy;
        }

        if (isset($options['curl']) && is_array($options['curl'])) {
            $transport['curl'] = $options['curl'];
        }

        return $transport;
    }

    /**
     * @phpstan-assert-if-true int|float $value
     */
    private static function isNumber(mixed $value): bool
    {
        return is_int($value) || is_float($value);
    }

    /**
     * A certificate or key: a path, or a path and its password.
     *
     * @return string|array{0: string, 1?: string|null}|null
     */
    private static function pathWithPassword(mixed $value): string|array|null
    {
        if (is_string($value)) {
            return $value;
        }

        if (! is_array($value) || ! isset($value[0]) || ! is_string($value[0])) {
            return null;
        }

        $password = $value[1] ?? null;

        return is_string($password) ? [$value[0], $password] : [$value[0]];
    }

    /**
     * A proxy: one URI for every scheme, or one per scheme with an exclusion list.
     *
     * @return string|array{http?: string|null, https?: string|null, no?: string|array<array-key, string>|null}|null
     */
    private static function proxy(mixed $value): string|array|null
    {
        if (is_string($value)) {
            return $value;
        }

        if (! is_array($value)) {
            return null;
        }

        $proxy = [];

        if (isset($value['http']) && is_string($value['http'])) {
            $proxy['http'] = $value['http'];
        }

        if (isset($value['https']) && is_string($value['https'])) {
            $proxy['https'] = $value['https'];
        }

        if (isset($value['no'])) {
            if (is_string($value['no'])) {
                $proxy['no'] = $value['no'];
            } elseif (is_array($value['no'])) {
                $proxy['no'] = array_values(array_filter($value['no'], is_string(...)));
            }
        }

        return $proxy === [] ? null : $proxy;
    }
}
